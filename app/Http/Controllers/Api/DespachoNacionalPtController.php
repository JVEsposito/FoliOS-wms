<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folio;
use App\Models\PartidaNacionalPt;
use App\Services\DespachoNacional\ServicioDespachoNacionalPt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DespachoNacionalPtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['estado' => ['nullable', 'in:pendiente,despachada']]);
        $pendientes = PartidaNacionalPt::query()->whereNull('despacho_id');
        $partidas = PartidaNacionalPt::query()
            ->with(['temporada:id,codigo', 'usuario:id,name', 'despacho.usuario:id,name'])
            ->when(($datos['estado'] ?? 'pendiente') === 'pendiente',
                fn ($q) => $q->whereNull('despacho_id'),
                fn ($q) => $q->whereNotNull('despacho_id'))
            ->latest()->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $partidas->items(),
            'meta' => ['pagina' => $partidas->currentPage(), 'paginas' => $partidas->lastPage(), 'total' => $partidas->total()],
            'resumen' => [
                'partidas_pendientes' => $pendientes->count(),
                'cajas_pendientes' => (int) $pendientes->sum('cajas'),
            ],
            'permisos' => [
                'separar' => $request->user()->can('gestionar-cargas'),
                'despachar' => $request->user()->can('cerrar-despacho-nacional-pt'),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function folio(Request $request, ServicioDespachoNacionalPt $servicio): JsonResponse
    {
        $datos = $request->validate(['numero' => ['required', 'string', 'max:50']]);
        $folio = Folio::query()->where('numero_folio', mb_strtoupper($datos['numero']))->firstOrFail();

        return response()->json(['data' => $servicio->consultar($folio)])->header('Cache-Control', 'no-store');
    }

    public function separar(Request $request, ServicioDespachoNacionalPt $servicio): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'folio_id' => ['required', 'uuid'],
            'cajas_esperadas' => ['required', 'integer', 'min:1'],
            'composicion' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'composicion.*' => ['required', 'array:clave,cajas'],
            'composicion.*.clave' => ['required', 'string', 'distinct:strict', 'max:700'],
            'composicion.*.cajas' => ['required', 'integer', 'min:1', 'max:1000000'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'confirmado' => ['accepted'],
        ]);
        $partida = $servicio->separar($datos, $request->user());

        return response()->json(['data' => $partida], $partida->wasRecentlyCreated ? 201 : 200);
    }

    public function despachar(Request $request, ServicioDespachoNacionalPt $servicio): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'partidas' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'partidas.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'destinatario' => ['required', 'string', 'max:160'],
            'guia' => ['required', 'string', 'max:60'],
            'patente' => ['nullable', 'string', 'max:20'],
            'confirmado' => ['accepted'],
        ]);
        $despacho = $servicio->despachar($datos, $request->user());

        return response()->json(['data' => $despacho], $despacho->wasRecentlyCreated ? 201 : 200);
    }
}
