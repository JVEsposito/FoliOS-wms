<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Periodo;
use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\GestorServicios;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiciosFinanzasController extends Controller
{
    public function store(Request $request, CatalogoFuentes $fuentes, GestorServicios $gestor, CatalogoServicios $catalogo): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'fuente' => ['required', Rule::in(array_keys($fuentes->todas()))],
            'aplicacion' => ['required', Rule::in([ConceptoServicio::ADICIONAL, ConceptoServicio::REEMPLAZA_BASE])],
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
