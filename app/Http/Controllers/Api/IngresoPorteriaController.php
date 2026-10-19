<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarIngresoPorteriaRequest;
use App\Models\Cliente;
use App\Models\IngresoPorteria;
use App\Services\Porteria\ServicioIngresosPorteria;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngresoPorteriaController extends Controller
{
    public function catalogos(): JsonResponse
    {
        return response()->json(['clientes' => Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']), 'ahora' => now()->toAtomString()])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, ServicioIngresosPorteria $servicio): JsonResponse
    {
        $filtros = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d'], 'buscar' => ['nullable', 'string', 'max:100'], 'pendientes' => ['nullable', 'boolean']]);
        $fecha = $filtros['fecha'] ?? CarbonImmutable::now('America/Santiago')->toDateString();
        $desde = CarbonImmutable::parse($fecha, 'America/Santiago')->startOfDay()->utc();
        $hasta = $desde->setTimezone('America/Santiago')->addDay()->startOfDay()->utc();
        $consulta = IngresoPorteria::query();
        if ($request->boolean('pendientes')) {
            $consulta->whereNull('anulado_at')->whereNull('revisado_at');
        } else {
            $consulta->where('ingreso_at', '>=', $desde)->where('ingreso_at', '<', $hasta);
        }
        if ($buscar = $filtros['buscar'] ?? null) {
            $consulta->where(fn ($q) => $q->where('patente', 'like', '%'.$buscar.'%')->orWhere('cliente_nombre', 'like', '%'.$buscar.'%')->orWhere('nombre_conductor', 'like', '%'.$buscar.'%')->orWhereJsonContains('guias', $buscar));
        }
        $ingresos = $consulta->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => $ingresos->getCollection()->map(fn (IngresoPorteria $ingreso): array => $servicio->presentar($ingreso)),
            'pagina' => $ingresos->currentPage(), 'ultima_pagina' => $ingresos->lastPage(), 'total' => $ingresos->total(), 'fecha' => $fecha,
            'puede_revisar' => $request->user()->can('revisar-porteria'), 'puede_descargar_pdf' => $request->user()->can('consultar-romana'),
            'puede_registrar' => $request->user()->can('registrar-porteria'), 'puede_administrar' => $request->user()->can('administrar-porteria'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(IngresoPorteria $ingresoPorteria, ServicioIngresosPorteria $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->presentar($ingresoPorteria), 'eventos' => $ingresoPorteria->eventos()->orderByDesc('id')->get(['accion', 'datos', 'user_nombre', 'created_at'])])->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarIngresoPorteriaRequest $request, ServicioIngresosPorteria $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->presentar($servicio->registrar($request->validated(), $request->user()))], 201);
    }

    public function update(GuardarIngresoPorteriaRequest $request, IngresoPorteria $ingresoPorteria, ServicioIngresosPorteria $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->presentar($servicio->corregir($ingresoPorteria, $request->validated(), $request->user()))]);
    }

    public function anular(Request $request, IngresoPorteria $ingresoPorteria, ServicioIngresosPorteria $servicio): JsonResponse
    {
        $datos = $request->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $servicio->presentar($servicio->anular($ingresoPorteria, $datos['version'], $datos['motivo'], $request->user()))]);
    }
}
