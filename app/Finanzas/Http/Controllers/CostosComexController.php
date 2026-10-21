<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Comex\GestorCostosComex;
use App\Finanzas\Comex\LectorComex;
use App\Finanzas\Comex\ValorCosto;
use App\Finanzas\Models\CostoComex;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\EventoCompra;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CostosComexController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100'], 'booking_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => CostoComex::query()->when($d['booking_id'] ?? null, fn ($q, $id) => $q->where('booking_id', $id))->when($d['buscar'] ?? null, fn ($q, $s) => $q->where('concepto', 'like', '%'.$s.'%'))->orderByDesc('created_at')->orderBy('id')->paginate(25), 'monedas' => ValorCosto::MONEDAS, 'puede_gestionar' => $r->user()->can('gestionar-costos-comex')]);
    }

    public function show(CostoComex $costo, GestorCostosComex $g): JsonResponse
    {
        return response()->json(['data' => $g->detalle($costo)]);
    }

    public function guardar(Request $r, GestorCostosComex $g, ?CostoComex $costo = null): JsonResponse
    {
        $monto = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'booking_id' => ['required', 'uuid'], 'contenedor_id' => ['nullable', 'uuid'], 'concepto' => ['required', 'string', 'max:200'], 'tratamiento' => ['required', 'in:costo_propio,reembolsable,por_definir'], 'fecha' => ['required', 'date_format:Y-m-d'], 'moneda' => ['required', Rule::in(array_keys(ValorCosto::MONEDAS))], 'previsto' => $monto, 'comprometido' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'], 'tasa_clp' => ['required_unless:moneda,CLP', 'string', 'regex:/^\d{1,12}(\.\d{1,8})?$/'], 'fecha_cambio' => ['required_unless:moneda,CLP', 'date_format:Y-m-d'], 'fuente_cambio' => ['required_unless:moneda,CLP', 'string', 'min:5', 'max:500'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000'], 'motivo' => [$costo ? 'required' : 'nullable', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->guardar($d, $r->user(), $costo)], $costo ? 200 : 201);
    }

    public function transicion(Request $r, CostoComex $costo, GestorCostosComex $g): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'], 'accion' => ['required', 'in:imputar,retirar_imputacion,cerrar,reabrir,anular'], 'motivo' => ['required', 'string', 'min:10', 'max:1000'], 'documento_id' => ['required_if:accion,imputar', 'uuid'], 'linea' => ['required_if:accion,imputar', 'integer', 'min:0', 'max:99'], 'monto' => ['required_if:accion,imputar', 'string', 'regex:/^\d{1,15}$/'], 'imputacion_id' => ['required_if:accion,retirar_imputacion', 'uuid']]);

        return response()->json(['data' => $g->cambiar($costo, $d, $r->user())]);
    }

    public function bookings(Request $r, LectorComex $lector): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $lector->bookings($d['buscar'] ?? null)]);
    }

    public function booking(string $booking, LectorComex $lector): JsonResponse
    {
        return response()->json(['data' => $lector->booking($booking)]);
    }

    public function documentos(Request $r): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => DocumentoCompra::query()->whereNotNull('registrado_at')->whereNull('anulado_at')->whereIn('tipo', ['33', '34', '56', '61', 'gasto'])->when($d['buscar'] ?? null, fn ($q, $s) => $q->where('folio', 'like', '%'.$s.'%'))->orderByDesc('fecha_emision')->orderBy('id')->paginate(25)]);
    }

    public function historial(CostoComex $costo): JsonResponse
    {
        return response()->json(['data' => EventoCompra::query()->where('entidad', 'costo_comex')->where('entidad_id', $costo->id)->orderByDesc('id')->paginate(50)]);
    }
}
