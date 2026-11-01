<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoOrdenProcesoMp;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelarOrdenProcesoMpRequest;
use App\Http\Requests\CrearOrdenProcesoMpRequest;
use App\Http\Requests\PublicarVersionOrdenProcesoMpRequest;
use App\Models\OrdenProcesoMp;
use App\Services\MateriaPrima\OrdenesProceso\PresentadorOrdenProcesoMp;
use App\Services\MateriaPrima\OrdenesProceso\ServicioOrdenProcesoMp;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class OrdenesProcesoMpController extends Controller
{
    public function __construct(
        private readonly ServicioOrdenProcesoMp $servicio,
        private readonly PresentadorOrdenProcesoMp $presentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(['abiertas', ...array_column(EstadoOrdenProcesoMp::cases(), 'value')])],
            'linea_proceso_id' => ['nullable', 'uuid'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            return response()->json(['data' => []]);
        }

        $ordenes = OrdenProcesoMp::query()
            ->with(['linea', 'versionVigente.lotes', 'reservas'])
            ->where('temporada_id', $temporada->id)
            ->when(($filtros['estado'] ?? 'abiertas') === 'abiertas',
                fn ($consulta) => $consulta->whereIn('estado', EstadoOrdenProcesoMp::abiertas()),
                fn ($consulta) => $consulta->where('estado', $filtros['estado']))
            ->when($filtros['linea_proceso_id'] ?? null, fn ($consulta, $linea) => $consulta->where('linea_proceso_id', $linea))
            ->when($filtros['desde'] ?? null, fn ($consulta, $desde) => $consulta->whereDate('fecha', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($consulta, $hasta) => $consulta->whereDate('fecha', '<=', $hasta))
            ->orderByDesc('fecha')
            ->orderBy('linea_proceso_id')
            ->orderByDesc('correlativo')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $ordenes->map(fn (OrdenProcesoMp $orden): array => $this->presentador->resumen($orden))->values(),
        ]);
    }

    public function show(OrdenProcesoMp $ordenProcesoMp): JsonResponse
    {
        return response()->json(['data' => $this->presentador->detalle($this->servicio->cargar($ordenProcesoMp))]);
    }

    public function lotesProgramables(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'orden_id' => ['nullable', 'uuid'],
            'buscar' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json([
            'data' => $this->servicio->lotesProgramables($filtros['orden_id'] ?? null, $filtros['buscar'] ?? null),
        ]);
    }

    public function store(CrearOrdenProcesoMpRequest $request): JsonResponse
    {
        $orden = $this->servicio->crear($request->validated(), $request->user());

        return response()->json(
            ['data' => $this->presentador->detalle($orden)],
            $orden->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function publicarVersion(PublicarVersionOrdenProcesoMpRequest $request, OrdenProcesoMp $ordenProcesoMp): JsonResponse
    {
        $orden = $this->servicio->publicarVersion($ordenProcesoMp, $request->validated(), $request->user());

        return response()->json(['data' => $this->presentador->detalle($orden)]);
    }

    public function cancelar(CancelarOrdenProcesoMpRequest $request, OrdenProcesoMp $ordenProcesoMp): JsonResponse
    {
        $datos = $request->validated();
        $orden = $this->servicio->cancelar($ordenProcesoMp, (int) $datos['version'], $datos['motivo'], $request->user());

        return response()->json(['data' => $this->presentador->detalle($orden)]);
    }
}
