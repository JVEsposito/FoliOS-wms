<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoSubloteRetornoPacking;
use App\Enums\TipoEnvaseRomana;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularEntregaFrutaProcesoRequest;
use App\Http\Requests\RegistrarEntregaFrutaProcesoRequest;
use App\Http\Resources\FrutaProcesoLoteResource;
use App\Models\EntregaFrutaProceso;
use App\Models\LoteMateriaPrima;
use App\Models\RetornoPacking;
use App\Models\SubloteRetornoPacking;
use App\Services\MateriaPrima\ServicioFrutaProceso;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class FrutaProcesoController extends Controller
{
    public function resumen(): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            return response()->json([
                'temporada' => null,
                'revision' => hash('sha256', 'sin-temporada-activa'),
                'lotes_abiertos' => 0,
                'lotes_completados' => 0,
                'bins_disponibles' => 0,
                'bins_entregados' => 0,
                'entregas_pendientes_retorno' => 0,
                'bins_retornados' => 0,
                'kilos_recuperados' => 0,
                'sublotes_pendientes_ubicacion' => 0,
                'retornos_registrados' => 0,
                'desglose_resultados' => [],
            ]);
        }

        $estadosAbiertos = [
            EstadoLoteMateriaPrima::DisponibleProceso->value,
            EstadoLoteMateriaPrima::AsignadoCamara->value,
            EstadoLoteMateriaPrima::EntregaParcialProceso->value,
        ];
        $estadosProceso = array_map(
            fn (EstadoLoteMateriaPrima $estado): string => $estado->value,
            $this->estadosProceso(),
        );
        $entregasPorLote = EntregaFrutaProceso::query()
            ->select('lote_materia_prima_id')
            ->selectRaw('SUM(cantidad_envases) as cantidad_entregada')
            ->selectRaw('MAX(updated_at) as ultima_actualizacion')
            ->whereNull('anulado_at')
            ->groupBy('lote_materia_prima_id');
        $resumenLotes = LoteMateriaPrima::query()
            ->leftJoinSub(
                $entregasPorLote,
                'entregas_vigentes',
                'entregas_vigentes.lote_materia_prima_id',
                '=',
                'lotes_materia_prima.id',
            )
            ->where('lotes_materia_prima.temporada_id', $temporada->id)
            ->where('lotes_materia_prima.envase_primario', TipoEnvaseRomana::Bins->value)
            ->whereIn('lotes_materia_prima.estado', $estadosProceso)
            ->selectRaw(
                'SUM(CASE WHEN lotes_materia_prima.estado IN (?, ?, ?) THEN 1 ELSE 0 END) as lotes_abiertos',
                $estadosAbiertos,
            )
            ->selectRaw(
                'SUM(CASE WHEN lotes_materia_prima.estado = ? THEN 1 ELSE 0 END) as lotes_completados',
                [EstadoLoteMateriaPrima::EntregadoProceso->value],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN lotes_materia_prima.cantidad_envases_primarios - COALESCE(entregas_vigentes.cantidad_entregada, 0) > 0 THEN lotes_materia_prima.cantidad_envases_primarios - COALESCE(entregas_vigentes.cantidad_entregada, 0) ELSE 0 END), 0) as bins_disponibles',
            )
            ->selectRaw('COALESCE(SUM(entregas_vigentes.cantidad_entregada), 0) as bins_entregados')
            ->selectRaw('MAX(lotes_materia_prima.updated_at) as lotes_actualizados_at')
            ->selectRaw('MAX(entregas_vigentes.ultima_actualizacion) as entregas_actualizadas_at')
            ->first();
        $entregasVigentes = EntregaFrutaProceso::query()
            ->whereNull('anulado_at')
            ->whereHas('lote', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporada->id));
        $sublotesVigentes = SubloteRetornoPacking::query()
            ->whereHas('retorno', fn (Builder $consulta) => $consulta
                ->whereNull('retornos_packing.anulado_at')
                ->whereHas('entregas', fn (Builder $entrega) => $entrega
                    ->whereNull('entregas_fruta_proceso.anulado_at')
                    ->whereHas('lote', fn (Builder $lote) => $lote
                        ->where('temporada_id', $temporada->id))));
        $sublotesPorTipo = (clone $sublotesVigentes)
            ->select('tipo_resultado_packing_id')
            ->selectRaw('COUNT(*) as sublotes')
            ->selectRaw('COALESCE(SUM(cantidad_bins), 0) as bins')
            ->selectRaw('COALESCE(SUM(kilos_netos), 0) as kilos')
            ->selectRaw('MAX(updated_at) as actualizados_at')
            ->selectRaw(
                'SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as pendientes_ubicacion',
                [EstadoSubloteRetornoPacking::PendienteUbicacion->value],
            )
            ->with('tipoResultado:id,codigo,nombre')
            ->groupBy('tipo_resultado_packing_id')
            ->orderBy('tipo_resultado_packing_id')
            ->get();
        $desgloseResultados = $sublotesPorTipo
            ->map(function (SubloteRetornoPacking $resumen): array {
                return [
                    'tipo' => [
                        'id' => $resumen->tipoResultado?->id,
                        'codigo' => $resumen->tipoResultado?->codigo,
                        'nombre' => $resumen->tipoResultado?->nombre,
                    ],
                    'sublotes' => (int) $resumen->getAttribute('sublotes'),
                    'bins' => (int) $resumen->getAttribute('bins'),
                    'kilos' => round((float) $resumen->getAttribute('kilos'), 3),
                ];
            })
            ->values();
        $resumenRetornos = RetornoPacking::query()
            ->whereNull('anulado_at')
            ->whereHas('entregas', fn (Builder $entrega) => $entrega
                ->whereNull('entregas_fruta_proceso.anulado_at')
                ->whereHas('lote', fn (Builder $lote) => $lote
                    ->where('temporada_id', $temporada->id)))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MAX(updated_at) as actualizados_at')
            ->first();
        $entregasPendientesRetorno = (clone $entregasVigentes)
            ->whereDoesntHave('retornos', fn (Builder $consulta) => $consulta
                ->whereNull('retornos_packing.anulado_at')
                ->where('retorno_packing_entregas.cierra_entrega', true))
            ->count();
        $revision = hash('sha256', json_encode([
            'temporada' => $temporada->id,
            'lotes' => $resumenLotes?->lotes_actualizados_at,
            'entregas' => $resumenLotes?->entregas_actualizadas_at,
            'retornos' => $resumenRetornos?->actualizados_at,
            'sublotes' => $sublotesPorTipo->max('actualizados_at'),
            'lotes_abiertos' => (int) ($resumenLotes?->lotes_abiertos ?? 0),
            'lotes_completados' => (int) ($resumenLotes?->lotes_completados ?? 0),
            'bins_disponibles' => (int) ($resumenLotes?->bins_disponibles ?? 0),
            'bins_entregados' => (int) ($resumenLotes?->bins_entregados ?? 0),
            'entregas_pendientes' => $entregasPendientesRetorno,
            'retornos_registrados' => (int) ($resumenRetornos?->total ?? 0),
            'resultados' => $sublotesPorTipo->map(fn (SubloteRetornoPacking $resumen): array => [
                'tipo' => $resumen->tipo_resultado_packing_id,
                'sublotes' => (int) $resumen->getAttribute('sublotes'),
                'bins' => (int) $resumen->getAttribute('bins'),
                'kilos' => (float) $resumen->getAttribute('kilos'),
                'pendientes' => (int) $resumen->getAttribute('pendientes_ubicacion'),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ],
            'revision' => $revision,
            'lotes_abiertos' => (int) ($resumenLotes?->lotes_abiertos ?? 0),
            'lotes_completados' => (int) ($resumenLotes?->lotes_completados ?? 0),
            'bins_disponibles' => (int) ($resumenLotes?->bins_disponibles ?? 0),
            'bins_entregados' => (int) ($resumenLotes?->bins_entregados ?? 0),
            'entregas_pendientes_retorno' => $entregasPendientesRetorno,
            'bins_retornados' => (int) $sublotesPorTipo->sum(
                fn (SubloteRetornoPacking $resumen): int => (int) $resumen->getAttribute('bins'),
            ),
            'kilos_recuperados' => round(
                (float) $sublotesPorTipo->sum(
                    fn (SubloteRetornoPacking $resumen): float => (float) $resumen->getAttribute('kilos'),
                ),
                3,
            ),
            'sublotes_pendientes_ubicacion' => (int) $sublotesPorTipo->sum(
                fn (SubloteRetornoPacking $resumen): int => (int) $resumen->getAttribute('pendientes_ubicacion'),
            ),
            'retornos_registrados' => (int) ($resumenRetornos?->total ?? 0),
            'desglose_resultados' => $desgloseResultados,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-fruta-proceso');
        $consulta = $this->consultaBase()
            ->when($request->filled('estado'), function (Builder $query) use ($request): void {
                $estado = $request->string('estado')->toString();
                if ($estado === 'abiertos') {
                    $query->whereIn('estado', [
                        EstadoLoteMateriaPrima::DisponibleProceso->value,
                        EstadoLoteMateriaPrima::AsignadoCamara->value,
                        EstadoLoteMateriaPrima::EntregaParcialProceso->value,
                    ]);
                } elseif ($estado === 'completados') {
                    $query->where('estado', EstadoLoteMateriaPrima::EntregadoProceso->value);
                }
            })
            ->when($request->filled('camara_id'), fn (Builder $query) => $query
                ->whereHas('asignacionCamara', fn (Builder $asignacion) => $asignacion
                    ->where('camara_id', $request->string('camara_id')->toString())))
            ->when($request->filled('buscar'), function (Builder $query) use ($request): void {
                $buscar = '%'.$request->string('buscar')->trim()->toString().'%';
                $query->where(function (Builder $filtro) use ($buscar): void {
                    $filtro->where('numero_lote', 'like', $buscar)
                        ->orWhere('csg_snapshot', 'like', $buscar)
                        ->orWhere('predio', 'like', $buscar)
                        ->orWhereHas('cliente', fn (Builder $cliente) => $cliente
                            ->where('nombre', 'like', $buscar)
                            ->orWhere('codigo', 'like', $buscar))
                        ->orWhereHas('entregasProceso', fn (Builder $entrega) => $entrega
                            ->where('numero_orden', 'like', $buscar));
                });
            })
            ->with($this->relaciones())
            ->orderByRaw("case estado when 'entrega_parcial_proceso' then 0 when 'disponible_proceso' then 1 when 'asignado_camara' then 2 else 3 end")
            ->orderBy('created_at');

        return FrutaProcesoLoteResource::collection(
            $consulta->paginate(min(200, max(1, $request->integer('per_page', 100)))),
        );
    }

    public function show(LoteMateriaPrima $loteMateriaPrima): FrutaProcesoLoteResource
    {
        Gate::authorize('consultar-fruta-proceso');
        abort_unless(
            $loteMateriaPrima->temporada_id === app(ServicioTemporadaActiva::class)->buscar()?->id
            && $loteMateriaPrima->envase_primario === TipoEnvaseRomana::Bins
            && in_array($loteMateriaPrima->estado, $this->estadosProceso(), true),
            Response::HTTP_NOT_FOUND,
        );

        return new FrutaProcesoLoteResource(
            $loteMateriaPrima->load($this->relaciones()),
        );
    }

    public function store(
        RegistrarEntregaFrutaProcesoRequest $request,
        LoteMateriaPrima $loteMateriaPrima,
        ServicioFrutaProceso $servicio,
    ): FrutaProcesoLoteResource {
        Gate::authorize('entregar-fruta-proceso');

        return new FrutaProcesoLoteResource(
            $servicio->registrar(
                $loteMateriaPrima,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function anular(
        AnularEntregaFrutaProcesoRequest $request,
        EntregaFrutaProceso $entregaFrutaProceso,
        ServicioFrutaProceso $servicio,
    ): FrutaProcesoLoteResource {
        Gate::authorize('anular-entregas-fruta-proceso');
        $datos = $request->validated();

        return new FrutaProcesoLoteResource(
            $servicio->anular(
                $entregaFrutaProceso,
                $datos['operacion_id'],
                $datos['motivo'],
                $request->user(),
            ),
        );
    }

    private function consultaBase(): Builder
    {
        return LoteMateriaPrima::query()
            ->whereHas('temporada', fn (Builder $consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->where('envase_primario', TipoEnvaseRomana::Bins->value)
            ->whereIn('estado', array_map(
                fn (EstadoLoteMateriaPrima $estado): string => $estado->value,
                $this->estadosProceso(),
            ));
    }

    /** @return array<int, EstadoLoteMateriaPrima> */
    private function estadosProceso(): array
    {
        return [
            EstadoLoteMateriaPrima::DisponibleProceso,
            EstadoLoteMateriaPrima::AsignadoCamara,
            EstadoLoteMateriaPrima::EntregaParcialProceso,
            EstadoLoteMateriaPrima::EntregadoProceso,
        ];
    }

    /** @return array<int, string> */
    private function relaciones(): array
    {
        return [
            'cliente',
            'recepcion',
            'asignacionCamara.camara',
            'entregasProceso.entregadoPor',
            'entregasProceso.anuladoPor',
            'entregasProceso.dispositivo',
            'entregasProceso.retornos.registradoPor',
            'entregasProceso.retornos.anuladoPor',
            'entregasProceso.retornos.dispositivo',
            'entregasProceso.retornos.entregas.lote',
            'entregasProceso.retornos.resultados.tipoResultado',
            'entregasProceso.retornos.resultados.camara',
            'entregasProceso.retornos.resultados.ubicadoPor',
            'entregasProceso.retornos.resultados.dispositivoUbicacion',
        ];
    }
}
