<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Periodo;
use App\Finanzas\Tarifario\GestorCargos;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CargosFinanzasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['mes' => ['nullable', 'date_format:Y-m']]);
        $periodo = Periodo::mes($datos['mes'] ?? null);

        return response()->json(['data' => CargoAcordado::query()->where('fecha', '<=', $periodo->fin->toDateString())
            ->where(fn ($q) => $q->where('fecha_hasta', '>=', $periodo->inicio->toDateString())->orWhere(fn ($p) => $p->whereNull('fecha_hasta')->where('fecha', '>=', $periodo->inicio->toDateString())))
            ->orderByDesc('fecha')->orderByDesc('id')->paginate(50)]);
    }

    public function store(Request $request, GestorCargos $gestor): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'], 'servicio' => ['required', 'string', 'max:40'], 'cliente_id' => ['required', 'uuid'],
            'fecha' => ['required', 'date_format:Y-m-d'], 'cantidad' => ['required', 'regex:/^-?\d{1,18}(?:\.\d{1,6})?$/'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha'],
            'base_cobro' => ['required', Rule::in(['ejecucion', 'acuerdo', 'disponibilidad', 'compromiso'])],
            'estado_ejecucion' => ['required', Rule::in(['realizado', 'programado', 'no_realizado', 'cancelado', 'no_aplica'])],
            'respaldo' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return response()->json(['data' => $gestor->crear($datos, $request->user())], 201);
    }

    public function aprobar(Request $request, CargoAcordado $cargo, GestorCargos $gestor): JsonResponse
    {
        return response()->json(['data' => $gestor->aprobar($cargo, $request->user())]);
    }

    public function anular(Request $request, CargoAcordado $cargo, GestorCargos $gestor): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $gestor->anular($cargo, $datos['motivo'], $request->user())]);
    }

    public function historial(CargoAcordado $cargo): JsonResponse
    {
        return response()->json(['data' => $cargo->eventos()->orderByDesc('id')->get()]);
    }

    public function finalizarPeriodo(Request $request, CargoAcordado $cargo, GestorCargos $gestor): JsonResponse
    {
        $datos = $request->validate(['fecha_hasta' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $gestor->finalizarPeriodo($cargo, $datos['fecha_hasta'], $datos['motivo'], $request->user())]);
    }
}
