<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JornadaFrigorifico;
use App\Models\PersonaFrigorifico;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Frigorifico\ControlPersonal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ControlPersonalFrigorificoController extends Controller
{
    private function filtros(Request $request): array
    {
        return $request->validate([
            'mes' => ['required', 'date_format:Y-m'],
            'persona_id' => ['nullable', 'uuid', 'exists:frigorifico_personas,id'],
            'sector' => ['nullable', 'string', 'max:80'],
            'vista' => ['nullable', Rule::in(['resumen', 'detalle'])],
        ]);
    }

    public function index(Request $request, ControlPersonal $servicio): JsonResponse
    {
        $filtros = $this->filtros($request);

        return response()->json(['data' => $servicio->reporte($filtros['mes'], $filtros['persona_id'] ?? null, $filtros['sector'] ?? null),
            'personas' => PersonaFrigorifico::query()->orderBy('nombre')->get(),
            'sectores' => PersonaFrigorifico::query()->select('sector')->union(JornadaFrigorifico::query()->select('sector'))
                ->orderBy('sector')->pluck('sector'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function persona(Request $request, ControlPersonal $servicio, ?string $id = null): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'identificador' => ['nullable', 'string', 'max:40', Rule::unique('frigorifico_personas')->ignore($id)],
            'sector' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'funcion' => ['nullable', 'string', 'max:80'], 'activo' => ['required', 'boolean'],
            'jornada_base_minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
        foreach (['nombre', 'sector', 'identificador', 'funcion'] as $campo) {
            $datos[$campo] = isset($datos[$campo]) ? (trim($datos[$campo]) ?: null) : null;
        }
        try {
            $persona = $servicio->guardarPersona($datos, $request->user(), $id);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identificador' => 'Ese identificador ya está registrado en otra persona.']);
        }

        return response()->json(['data' => $persona], $id ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    public function jornada(Request $request, ControlPersonal $servicio, ?string $id = null): JsonResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', 'uuid', 'exists:frigorifico_personas,id'],
            'ingreso' => ['required', 'date_format:Y-m-d\TH:i'], 'salida' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'jornada_base_minutos' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'colacion_minutos' => ['required', 'integer', 'min:0', 'max:240'],
            'sector' => ['nullable', 'string', 'max:80', 'regex:/\S/u'], 'observacion' => ['nullable', 'string', 'max:1000'],
            'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
            'motivo' => [$id ? 'required' : 'nullable', 'string', 'max:500', 'regex:/\S/u'],
        ]);

        return response()->json(['data' => $servicio->guardarJornada($datos, $request->user(), $id)], $id ? 200 : 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function anular(Request $request, ControlPersonal $servicio, string $id): JsonResponse
    {
        $datos = $request->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'max:500', 'regex:/\S/u']]);
        $servicio->anular($id, $datos, $request->user());

        return response()->json(['data' => ['anulada' => true]])->header('Cache-Control', 'no-store, private');
    }

    public function historial(string $id): JsonResponse
    {
        JornadaFrigorifico::query()->findOrFail($id);
        $eventos = DB::table('frigorifico_control_personal_eventos as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.jornada_id', $id)->orderByDesc('e.id')->get(['e.*', 'u.name as responsable'])
            ->map(fn ($e) => [...(array) $e, 'anterior' => json_decode($e->anterior ?? 'null', true), 'nuevo' => json_decode($e->nuevo, true)]);

        return response()->json(['data' => $eventos])->header('Cache-Control', 'no-store, private');
    }

    public function exportar(Request $request, ControlPersonal $servicio, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        $estado = $servicio->reporte($filtros['mes'], $filtros['persona_id'] ?? null, $filtros['sector'] ?? null);
        $detalle = ($filtros['vista'] ?? 'resumen') === 'detalle';
        $filas = array_map(function ($fila) use ($detalle) {
            if ($detalle) {
                $fila['nombre'] = $fila['persona']['nombre'];
                $fila['identificador'] = $fila['persona']['identificador'];
                if ($fila['estado'] === 'anulada') {
                    $fila['minutos_trabajados'] = $fila['minutos_extra'] = null;
                }
            }
            foreach (['minutos_trabajados' => 'horas_trabajadas', 'minutos_extra' => 'horas_extra'] as $campo => $titulo) {
                $valor = $fila[$campo];
                $fila[$titulo] = $valor === null ? '' : sprintf('%02d:%02d', intdiv($valor, 60), $valor % 60);
            }

            return $fila;
        }, $detalle ? $estado['jornadas'] : $estado['resumen']);
        $columnas = [['clave' => 'nombre', 'titulo' => 'Persona'], ['clave' => 'identificador', 'titulo' => 'Identificador']];
        $columnas = [...$columnas, ...($detalle ? [
            ['clave' => 'sector', 'titulo' => 'Sector'], ['clave' => 'ingreso', 'titulo' => 'Ingreso (hora planta)'],
            ['clave' => 'salida', 'titulo' => 'Salida (hora planta)'], ['clave' => 'estado', 'titulo' => 'Estado'],
            ['clave' => 'jornada_base_minutos', 'titulo' => 'Jornada base (min)', 'tipo' => 'numero'],
            ['clave' => 'colacion_minutos', 'titulo' => 'Colación (min)', 'tipo' => 'numero'],
        ] : [
            ['clave' => 'jornadas_cerradas', 'titulo' => 'Jornadas cerradas', 'tipo' => 'numero'],
            ['clave' => 'pendientes', 'titulo' => 'Salidas pendientes', 'tipo' => 'numero'],
        ]), ['clave' => 'horas_trabajadas', 'titulo' => 'Trabajado (HH:MM)'], ['clave' => 'horas_extra', 'titulo' => 'Extra (HH:MM)'],
            ['clave' => 'minutos_trabajados', 'titulo' => 'Trabajado (min)', 'tipo' => 'numero'],
            ['clave' => 'minutos_extra', 'titulo' => 'Extra (min)', 'tipo' => 'numero']];
        if ($detalle) {
            $columnas[] = ['clave' => 'observacion', 'titulo' => 'Observación'];
        }
        $ruta = $excel->generar('Control interno de personal · Frigorífico', $columnas, $filas, [
            'usuario' => $request->user()->name, 'periodo' => $filtros['mes'],
            'adicionales' => [['Criterio', $estado['meta']['criterio']], ['Cálculo', 'Extra = máximo entre 0 y permanencia menos colación menos jornada base. Control interno, sin liquidación de remuneraciones.'], ['Zona horaria', $estado['meta']['zona_horaria']]],
        ], $detalle ? 'Jornadas' : 'Resumen mensual');

        return response()->download($ruta, 'control-personal-'.$filtros['mes'].'-'.($detalle ? 'detalle' : 'resumen').'.xlsx', ['Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }
}
