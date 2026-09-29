<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BinRetornoPacking;
use App\Models\DespachoComercialRetorno;
use App\Services\MateriaPrima\ServicioDespachoComercialRetorno;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DespachoComercialRetornoController extends Controller
{
    public function index(ServicioTemporadaActiva $temporadas): JsonResponse
    {
        Gate::authorize('consultar-despacho-comercial');
        $temporadaId = $temporadas->obtener()->id;

        return response()->json([
            'data' => DespachoComercialRetorno::query()->where('temporada_id', $temporadaId)
                ->with(['bins', 'creadoPor:id,name', 'confirmadoPor:id,name'])
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function disponibles(ServicioTemporadaActiva $temporadas): JsonResponse
    {
        Gate::authorize('consultar-despacho-comercial');

        return response()->json([
            'data' => BinRetornoPacking::query()
                ->where('temporada_id', $temporadas->obtener()->id)
                ->where('estado', 'regularizado')->whereNull('anulado_at')
                ->whereNull('despacho_comercial_id')->whereNotNull('folio_definitivo_vigente')
                ->whereNotNull('kilos_totales_definitivos')
                ->where(fn ($query) => $query->whereNotNull('nombre_resultado')->orWhereNotNull('tipo_resultado_packing_id'))
                ->with(['tipoResultado:id,nombre', 'origenes:id,bin_retorno_packing_id,numero_lote,numero_orden'])
                ->orderBy('folio_definitivo_vigente')->get()
                ->map(fn (BinRetornoPacking $bin): array => [
                    'id' => $bin->id,
                    'folio' => $bin->folio_definitivo_vigente,
                    'clasificacion' => $bin->nombre_resultado ?: $bin->tipoResultado?->nombre,
                    'kilos' => $bin->kilos_totales_definitivos,
                    'origenes' => $bin->origenes->map(fn ($origen): array => [
                        'lote' => $origen->numero_lote,
                        'orden' => $origen->numero_orden,
                    ]),
                ]),
        ]);
    }

    public function store(Request $request, ServicioDespachoComercialRetorno $servicio): JsonResponse
    {
        Gate::authorize('gestionar-despacho-comercial');
        $datos = $request->validate([
            'destinatario' => ['required', 'string', 'max:180'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'bins' => ['required', 'array', 'min:1', 'max:200'],
            'bins.*' => ['required', 'uuid', 'distinct'],
        ]);

        return response()->json(['data' => $servicio->crear($datos, $request->user())], 201);
    }

    public function confirmar(Request $request, DespachoComercialRetorno $despacho, ServicioDespachoComercialRetorno $servicio): JsonResponse
    {
        Gate::authorize('gestionar-despacho-comercial');
        $datos = $request->validate([
            'numero_guia_sii' => ['required', 'string', 'max:40', 'regex:/^[0-9]+$/'],
        ]);

        return response()->json(['data' => $servicio->confirmar($despacho, $datos['numero_guia_sii'], $request->user())]);
    }

    public function cancelar(Request $request, DespachoComercialRetorno $despacho, ServicioDespachoComercialRetorno $servicio): JsonResponse
    {
        Gate::authorize('gestionar-despacho-comercial');

        return response()->json(['data' => $servicio->cancelar($despacho, $request->user())]);
    }
}
