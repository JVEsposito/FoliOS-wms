<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AusenciaFrigorifico;
use App\Models\JornadaFrigorifico;
use App\Models\PersonaFrigorifico;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Frigorifico\ControlPersonal;
use App\Services\Frigorifico\FichaMensualPersonalPdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'vista' => ['nullable', Rule::in(['resumen', 'detalle', 'ausencias'])],
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

        return $this->eventos('jornada_id', $id);
    }

    public function ausencia(Request $request, ControlPersonal $servicio, ?string $id = null): JsonResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', 'uuid', 'exists:frigorifico_personas,id'],
            'tipo' => ['required', Rule::in(array_keys(AusenciaFrigorifico::TIPOS))],
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
            'motivo' => [$id ? 'required' : 'nullable', 'string', 'max:500', 'regex:/\S/u'],
        ]);
        $datos['observacion'] = isset($datos['observacion']) ? (trim($datos['observacion']) ?: null) : null;

        return response()->json(['data' => $servicio->guardarAusencia($datos, $request->user(), $id)], $id ? 200 : 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function anularAusencia(Request $request, ControlPersonal $servicio, string $id): JsonResponse
    {
        $datos = $request->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'max:500', 'regex:/\S/u']]);
        $servicio->anularAusencia($id, $datos, $request->user());

        return response()->json(['data' => ['anulada' => true]])->header('Cache-Control', 'no-store, private');
    }

    public function historialAusencia(string $id): JsonResponse
    {
        AusenciaFrigorifico::query()->findOrFail($id);

        return $this->eventos('ausencia_id', $id);
    }

    private function eventos(string $columna, string $id): JsonResponse
    {
        $eventos = DB::table('frigorifico_control_personal_eventos as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.'.$columna, $id)->orderByDesc('e.id')->get(['e.*', 'u.name as responsable'])
            ->map(fn ($e) => [...(array) $e, 'anterior' => json_decode($e->anterior ?? 'null', true), 'nuevo' => json_decode($e->nuevo, true)]);

        return response()->json(['data' => $eventos])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Ficha mensual día a día en PDF, para revisar con el trabajador. Con persona_id genera la de esa persona;
     * sin ella, una ficha por cada persona con jornadas o ausencias en el mes (respetando el sector).
     */
    public function ficha(Request $request, ControlPersonal $servicio, FichaMensualPersonalPdf $pdf): Response
    {
        $filtros = $this->filtros($request);
        if (! empty($filtros['persona_id'])) {
            $personas = PersonaFrigorifico::query()->whereKey($filtros['persona_id'])->get();
        } else {
            $ids = array_column($servicio->reporte($filtros['mes'], null, $filtros['sector'] ?? null)['resumen'], 'persona_id');
            $personas = PersonaFrigorifico::query()->whereKey($ids)->get()->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)->values();
        }
        if ($personas->isEmpty()) {
            throw ValidationException::withMessages(['mes' => 'No hay jornadas ni ausencias registradas para generar fichas con estos filtros.']);
        }
        $fichas = $personas->map(fn (PersonaFrigorifico $p): array => $servicio->ficha($filtros['mes'], $p))->all();
        $nombre = 'ficha-personal-'.$filtros['mes'].'-'.($personas->count() === 1 ? str($personas->first()->nombre)->slug() : 'todas').'.pdf';

        return response($pdf->generar($fichas, $request->user()->name))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$nombre.'"')
            ->header('Cache-Control', 'no-store, private');
    }

    public function exportar(Request $request, ControlPersonal $servicio, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        $estado = $servicio->reporte($filtros['mes'], $filtros['persona_id'] ?? null, $filtros['sector'] ?? null);
        $vista = $filtros['vista'] ?? 'resumen';
        if ($vista === 'ausencias') {
            return $this->exportarAusencias($request, $estado, $excel);
        }
        $detalle = $vista === 'detalle';
        $filas = array_map(function ($fila) use ($detalle) {
            if ($detalle) {
                $fila['nombre'] = $fila['persona']['nombre'];
                $fila['identificador'] = $fila['persona']['identificador'];
                if ($fila['estado'] === 'anulada') {
                    $fila['minutos_trabajados'] = $fila['minutos_extra'] = null;
                }
            }
            if (! $detalle) {
                foreach (AusenciaFrigorifico::TIPOS as $tipo => $_) {
                    $fila['ausencia_'.$tipo] = $fila['ausencias'][$tipo];
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
        } else {
            foreach (AusenciaFrigorifico::TIPOS as $tipo => $nombre) {
                $columnas[] = ['clave' => 'ausencia_'.$tipo, 'titulo' => $nombre.' (días)', 'tipo' => 'numero'];
            }
        }
        $ruta = $excel->generar('Control interno de personal · Frigorífico', $columnas, $filas, [
            'usuario' => $request->user()->name, 'periodo' => $filtros['mes'],
            'adicionales' => [['Criterio', $estado['meta']['criterio']], ['Cálculo', 'Extra = máximo entre 0 y permanencia menos colación menos jornada base. Control interno, sin liquidación de remuneraciones.'], ['Zona horaria', $estado['meta']['zona_horaria']]],
        ], $detalle ? 'Jornadas' : 'Resumen mensual');

        return response()->download($ruta, 'control-personal-'.$filtros['mes'].'-'.($detalle ? 'detalle' : 'resumen').'.xlsx', ['Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }

    private function exportarAusencias(Request $request, array $estado, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        $formato = fn (string $fecha): string => implode('-', array_reverse(explode('-', $fecha)));
        $filas = array_map(fn (array $a): array => [...$a, 'nombre' => $a['persona']['nombre'], 'identificador' => $a['persona']['identificador'],
            'desde_planta' => $formato($a['desde']), 'hasta_planta' => $formato($a['hasta']),
            'dias_mes' => $a['estado'] === 'anulada' ? null : $a['dias_mes']], $estado['ausencias']);
        $columnas = [['clave' => 'nombre', 'titulo' => 'Persona'], ['clave' => 'identificador', 'titulo' => 'Identificador'],
            ['clave' => 'tipo_nombre', 'titulo' => 'Tipo'], ['clave' => 'desde_planta', 'titulo' => 'Desde'], ['clave' => 'hasta_planta', 'titulo' => 'Hasta'],
            ['clave' => 'estado', 'titulo' => 'Estado'], ['clave' => 'dias_mes', 'titulo' => 'Días en el mes', 'tipo' => 'numero'],
            ['clave' => 'dias_total', 'titulo' => 'Días del periodo', 'tipo' => 'numero'], ['clave' => 'observacion', 'titulo' => 'Observación']];
        $mes = $estado['meta']['mes'];
        $ruta = $excel->generar('Control interno de personal · Ausencias', $columnas, $filas, [
            'usuario' => $request->user()->name, 'periodo' => $mes,
            'adicionales' => [['Criterio', 'Días completos sin jornada registrados por el supervisor. Las anuladas no suman días.'], ['Zona horaria', $estado['meta']['zona_horaria']]],
        ], 'Ausencias');

        return response()->download($ruta, 'control-personal-'.$mes.'-ausencias.xlsx', ['Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }
}
