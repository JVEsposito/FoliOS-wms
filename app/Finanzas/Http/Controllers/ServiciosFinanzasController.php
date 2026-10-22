<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Periodo;
use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\GestorServicios;
use App\Finanzas\Valorizacion\ConsultaRegla;
use App\Finanzas\Valorizacion\ReglaValorizacion;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiciosFinanzasController extends Controller
{
    public function store(Request $request, CatalogoFuentes $fuentes, GestorServicios $gestor, CatalogoServicios $catalogo): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'unidad_libre' => ['required_if:fuente,declarado,calculado', 'nullable', 'string', 'max:60'],
            'area' => ['sometimes', 'required', 'string', 'max:60'],
            'regla' => ['required_if:fuente,calculado', 'nullable', 'array'],
            'proceso_referencia' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'fuente' => ['required', Rule::in(array_keys($fuentes->todas()))],
            'aplicacion' => ['required', Rule::in([ConceptoServicio::ADICIONAL, ConceptoServicio::REEMPLAZA_BASE, 'declarado', 'calculado'])],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'],
            'dia_desde' => ['required', 'integer', 'between:1,65535'],
            'dia_hasta' => ['nullable', 'integer', 'between:1,65535', 'gte:dia_desde'],
        ], [
            'required' => 'Indique :attribute.', 'fuente.in' => 'El proceso no está disponible para Finanzas.',
            'aplicacion.in' => 'Elija si se suma o reemplaza al concepto base.',
            'vigente_hasta.after_or_equal' => 'El término no puede ser anterior al inicio.',
            'dia_hasta.gte' => 'El último día no puede ser anterior al primero.',
        ]);
        $servicio = $gestor->crear(['descripcion' => null, 'vigente_hasta' => null, 'dia_hasta' => null, ...$datos, 'dia_desde' => (int) $datos['dia_desde'], 'dia_hasta' => isset($datos['dia_hasta']) ? (int) $datos['dia_hasta'] : null], $request->user());
        $catalogo->recargar();

        return response()->json(['data' => $catalogo->buscar($servicio->clave)], 201);
    }

    public function simular(Request $request, ReglaValorizacion $reglas, ConsultaRegla $consulta, EsquemaFinanzas $esquema): JsonResponse
    {
        $datos = $request->validate(['regla' => ['required', 'array'], 'vigente_desde' => ['required', 'date_format:Y-m-d'], 'vigente_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde']]);
        $regla = $reglas->normalizar($datos['regla']);
        try {
            $grupos = $esquema->transaccion(fn (): array => $consulta->grupos($regla));
        } catch (DomainException $error) {
            throw ValidationException::withMessages(['regla' => $error->getMessage()]);
        }
        $grupos = array_values(array_filter($grupos, fn (array $grupo): bool => $grupo['ancla'] !== null && $grupo['ancla']['fecha_operacional'] >= $datos['vigente_desde'] && $grupo['ancla']['fecha_operacional'] <= ($datos['vigente_hasta'] ?? '9999-12-31')));

        return response()->json(['data' => ['unidad' => $regla['unidad'], 'grupos' => count($grupos), 'incompletos' => count(array_filter($grupos, fn (array $grupo): bool => ! $grupo['completo'])), 'muestra' => array_map(fn (array $grupo): array => [
            'cliente_id' => $grupo['ancla']['cliente_id'], 'cliente_nombre' => $grupo['ancla']['cliente_nombre'], 'fecha' => $grupo['ancla']['fecha_operacional'],
            'dimensiones' => $grupo['dimensiones'], 'cantidad' => $grupo['cantidad'], 'completo' => $grupo['completo'], 'aportes' => $grupo['aportes'],
        ], array_slice($grupos, 0, 20)), 'aviso' => 'Simulación con los hechos capturados. Los grupos incompletos no se cobran. Actualice la lectura para incorporar cambios operativos.']]);
    }

    public function finalizar(Request $request, ConceptoServicio $servicio, GestorServicios $gestor, CatalogoServicios $catalogo): JsonResponse
    {
        $datos = $request->validate([
            'vigente_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Periodo::hoy()],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], ['vigente_hasta.after_or_equal' => 'El término no puede ser anterior a hoy.']);
        $gestor->finalizar($servicio, $datos['vigente_hasta'], $datos['motivo'], $request->user());
        $catalogo->recargar();

        return response()->json(['data' => $catalogo->buscar($servicio->clave)]);
    }

    public function historial(ConceptoServicio $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->eventos()->orderByDesc('id')->get()->map(fn ($evento): array => [
            'accion' => $evento->accion, 'datos' => $evento->datos, 'motivo' => $evento->motivo,
            'usuario' => $evento->user_nombre, 'fecha' => $evento->created_at->toIso8601String(),
        ])]);
    }
}
