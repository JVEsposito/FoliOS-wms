<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnularBinRetornoPackingRequest;
use App\Http\Requests\DescartarRetornoPackingLegacyRequest;
use App\Http\Requests\MigrarRetornoPackingLegacyRequest;
use App\Http\Requests\ModificarBinRetornoPackingRequest;
use App\Http\Requests\RegistrarBinRetornoPackingRequest;
use App\Http\Requests\RegularizarBinRetornoPackingRequest;
use App\Models\BinRetornoPacking;
use App\Models\EntregaFrutaProceso;
use App\Models\RegularizacionRetornoPackingLegacy;
use App\Models\RetornoPacking;
use App\Models\TipoResultadoPacking;
use App\Services\MateriaPrima\ServicioBinRetornoPacking;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BinRetornoPackingController extends Controller
{
    public function resumen(): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');

        $bins = BinRetornoPacking::query()
            ->whereNull('anulado_at')
            ->whereHas('temporada', fn (Builder $consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id));
        $regularizados = (clone $bins)->where('estado', 'regularizado');
        $pendientes = (clone $bins)->where('estado', 'pendiente_regularizacion');
        $legadoResuelto = RegularizacionRetornoPackingLegacy::query()
            ->pluck('retorno_packing_id');
        $legadoPendiente = RetornoPacking::query()
            ->whereNull('anulado_at')
            ->whereHas(
                'entregas.lote.temporada',
                fn (Builder $consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id),
            )
            ->whereNotIn('id', $legadoResuelto)
            ->count();

        return response()->json([
            'bins_registrados' => (clone $bins)->count(),
            'kilos_registrados' => round((float) (clone $bins)->sum('kilos_totales'), 3),
            'kilos_definitivos' => round(
                (float) (clone $regularizados)->sum('kilos_totales_definitivos'),
                3,
            ),
            'pendientes_regularizacion' => $pendientes->count(),
            'regularizados' => $regularizados->count(),
            'retornos_anteriores_pendientes' => $legadoPendiente,
        ]);
    }

    public function procesos(): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');

        $entregas = EntregaFrutaProceso::query()
            ->with('lote:id,numero_lote')
            ->whereNull('anulado_at')
            ->whereHas('lote.temporada', fn (Builder $consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->orderByDesc('entregado_at')
            ->get();

        $procesos = $entregas
            ->groupBy(fn (EntregaFrutaProceso $entrega): string => $this->claveProceso($entrega))
            ->map(function ($grupo, string $clave): array {
                /** @var EntregaFrutaProceso $principal */
                $principal = $grupo->first();
                $kilos = $grupo->filter(fn (EntregaFrutaProceso $entrega): bool => $entrega->kilos_enviados !== null);

                return [
                    'clave' => $clave,
                    'lote_materia_prima_id' => $principal->lote_materia_prima_id,
                    'numero_lote' => $principal->lote?->numero_lote,
                    'numero_orden' => $principal->numero_orden,
                    'linea_proceso' => $principal->linea_proceso,
                    'turno' => $principal->turno,
                    'viajes' => $grupo->count(),
                    'bins_enviados' => (int) $grupo->sum('cantidad_envases'),
                    'kilos_enviados' => $kilos->isEmpty()
                        ? null
                        : round((float) $kilos->sum('kilos_enviados'), 3),
                    'ultimo_envio_at' => $grupo->max('entregado_at')?->toAtomString(),
                ];
            })
            ->sortByDesc('ultimo_envio_at')
            ->values();

        return response()->json(['data' => $procesos]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');

        $estado = trim($request->string('estado')->value());
        $buscar = trim($request->string('buscar')->value());
        $bins = BinRetornoPacking::query()
            ->whereHas('temporada', fn (Builder $consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with([
                'origenes.lote:id,numero_lote',
                'tipoResultado:id,codigo,nombre',
                'registradoPor:id,name',
                'regularizadoPor:id,name',
                'anuladoPor:id,name',
                'retornoLegacy:id,numero',
                'ultimaModificacion.modificadoPor:id,name',
            ])
            ->when($estado !== '', fn (Builder $consulta) => $consulta->where('estado', $estado))
            ->when($buscar !== '', function (Builder $consulta) use ($buscar): void {
                $consulta->where(function (Builder $subconsulta) use ($buscar): void {
                    $subconsulta
                        ->where('folio_provisional', 'like', "%{$buscar}%")
                        ->orWhere('folio_definitivo', 'like', "%{$buscar}%")
                        ->orWhere('observacion', 'like', "%{$buscar}%")
                        ->orWhereHas('origenes', fn (Builder $origenes) => $origenes
                            ->where('numero_lote', 'like', "%{$buscar}%")
                            ->orWhere('numero_orden', 'like', "%{$buscar}%"));
                });
            })
            ->latest('registrado_at')
            ->get()
            ->map(fn (BinRetornoPacking $bin): array => $this->bin($bin));

        return response()->json(['data' => $bins]);
    }

    public function legacy(): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');

        $resueltos = RegularizacionRetornoPackingLegacy::query()
            ->pluck('retorno_packing_id');
        $retornos = RetornoPacking::query()
            ->whereHas(
                'entregas.lote.temporada',
                fn (Builder $consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id),
            )
            ->with([
                'entregas.lote:id,numero_lote',
                'resultados.tipoResultado:id,codigo,nombre',
                'registradoPor:id,name',
            ])
            ->whereNull('anulado_at')
            ->whereNotIn('id', $resueltos)
            ->latest('registrado_at')
            ->limit(300)
            ->get()
            ->map(function (RetornoPacking $retorno): array {
                $resultadoUnico = $retorno->resultados->count() === 1
                    ? $retorno->resultados->first()
                    : null;
                $migrable = $resultadoUnico && (int) $resultadoUnico->cantidad_bins === 1;

                return [
                    'id' => $retorno->id,
                    'numero' => $retorno->numero,
                    'registrado_at' => $retorno->registrado_at?->toAtomString(),
                    'registrado_por' => $retorno->registradoPor?->name,
                    'migrable' => (bool) $migrable,
                    'motivo_no_migrable' => $migrable
                        ? null
                        : 'El registro anterior agrupa más de un bin o más de un resultado; debe descartarse y reingresarse bin por bin.',
                    'kilos_sugeridos' => $migrable && $resultadoUnico->kilos_netos !== null
                        ? (float) $resultadoUnico->kilos_netos
                        : null,
                    'resultados' => $retorno->resultados->map(fn ($resultado): array => [
                        'numero_sublote' => $resultado->numero_sublote,
                        'nombre_resultado' => $resultado->nombre_resultado,
                        'cantidad_bins' => (int) $resultado->cantidad_bins,
                        'kilos_netos' => $resultado->kilos_netos !== null
                            ? (float) $resultado->kilos_netos
                            : null,
                    ])->values(),
                    'procesos' => $retorno->entregas
                        ->unique(fn (EntregaFrutaProceso $entrega): string => $this->claveProceso($entrega))
                        ->map(fn (EntregaFrutaProceso $entrega): array => [
                            'clave' => $this->claveProceso($entrega),
                            'lote_materia_prima_id' => $entrega->lote_materia_prima_id,
                            'numero_lote' => $entrega->lote?->numero_lote,
                            'numero_orden' => $entrega->numero_orden,
                            'linea_proceso' => $entrega->linea_proceso,
                            'turno' => $entrega->turno,
                        ])->values(),
                ];
            });

        return response()->json(['data' => $retornos]);
    }

    public function catalogos(): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');

        return response()->json([
            'tipos_resultado' => TipoResultadoPacking::query()
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function store(
        RegistrarBinRetornoPackingRequest $request,
        ServicioBinRetornoPacking $servicio,
    ): JsonResponse {
        Gate::authorize('entregar-fruta-proceso');
        $bin = $servicio->registrar($request->validated(), $request->user());

        return response()->json(['data' => $this->bin($bin)], 201);
    }

    public function modificar(
        ModificarBinRetornoPackingRequest $request,
        BinRetornoPacking $binRetornoPacking,
        ServicioBinRetornoPacking $servicio,
    ): JsonResponse {
        Gate::authorize('anular-entregas-fruta-proceso');
        $bin = $servicio->modificar(
            $binRetornoPacking,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->bin($bin)]);
    }

    public function regularizar(
        RegularizarBinRetornoPackingRequest $request,
        BinRetornoPacking $binRetornoPacking,
        ServicioBinRetornoPacking $servicio,
    ): JsonResponse {
        Gate::authorize('entregar-fruta-proceso');
        $bin = $servicio->regularizar(
            $binRetornoPacking,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->bin($bin)]);
    }

    public function anular(
        AnularBinRetornoPackingRequest $request,
        BinRetornoPacking $binRetornoPacking,
        ServicioBinRetornoPacking $servicio,
    ): JsonResponse {
        Gate::authorize('anular-entregas-fruta-proceso');
        $bin = $servicio->anular(
            $binRetornoPacking,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->bin($bin)]);
    }

    public function migrarLegacy(
        MigrarRetornoPackingLegacyRequest $request,
        RetornoPacking $retornoPacking,
        ServicioBinRetornoPacking $servicio,
    ): JsonResponse {
        Gate::authorize('anular-entregas-fruta-proceso');
        $bin = $servicio->migrarLegacy(
            $retornoPacking,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->bin($bin)], 201);
    }

    public function descartarLegacy(
        DescartarRetornoPackingLegacyRequest $request,
        RetornoPacking $retornoPacking,
        ServicioBinRetornoPacking $servicio,
    ): JsonResponse {
        Gate::authorize('anular-entregas-fruta-proceso');
        $servicio->descartarLegacy(
            $retornoPacking,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['message' => 'Retorno anterior descartado. Reingresa sus bins individualmente.']);
    }

    private function bin(BinRetornoPacking $bin): array
    {
        return [
            'id' => $bin->id,
            'folio_provisional' => $bin->folio_provisional,
            'folio_definitivo' => $bin->folio_definitivo,
            'kilos_totales' => (float) $bin->kilos_totales,
            'kilos_totales_verdes' => (float) $bin->kilos_totales,
            'kilos_totales_definitivos' => $bin->kilos_totales_definitivos !== null
                ? (float) $bin->kilos_totales_definitivos
                : null,
            'estado' => $bin->estado,
            'tipo_resultado' => $bin->tipoResultado ? [
                'id' => $bin->tipoResultado->id,
                'codigo' => $bin->tipoResultado->codigo,
                'nombre' => $bin->tipoResultado->nombre,
            ] : null,
            'nombre_resultado' => $bin->nombre_resultado,
            'origenes' => $bin->origenes->map(fn ($origen): array => [
                'id' => $origen->id,
                'clave' => $origen->clave_proceso,
                'lote_materia_prima_id' => $origen->lote_materia_prima_id,
                'numero_lote' => $origen->numero_lote ?: $origen->lote?->numero_lote,
                'numero_orden' => $origen->numero_orden,
                'linea_proceso' => $origen->linea_proceso,
                'turno' => $origen->turno,
                'kilos_aportados' => (float) $origen->kilos_aportados,
                'kilos_aportados_verdes' => (float) $origen->kilos_aportados,
                'kilos_aportados_definitivos' => $origen->kilos_aportados_definitivos !== null
                    ? (float) $origen->kilos_aportados_definitivos
                    : null,
            ])->values(),
            'registrado_por' => $bin->registradoPor?->name,
            'registrado_at' => $bin->registrado_at?->toAtomString(),
            'regularizado_por' => $bin->regularizadoPor?->name,
            'regularizado_at' => $bin->regularizado_at?->toAtomString(),
            'anulado_por' => $bin->anuladoPor?->name,
            'anulado_at' => $bin->anulado_at?->toAtomString(),
            'motivo_anulacion' => $bin->motivo_anulacion,
            'retorno_legacy' => $bin->retornoLegacy?->numero,
            'observacion' => $bin->observacion,
            'modificado_por' => $bin->ultimaModificacion?->modificadoPor?->name,
            'modificado_at' => $bin->ultimaModificacion?->modificado_at?->toAtomString(),
            'motivo_ultima_modificacion' => $bin->ultimaModificacion?->motivo,
        ];
    }

    private function claveProceso(EntregaFrutaProceso $entrega): string
    {
        return hash('sha256', implode('|', [
            $entrega->lote_materia_prima_id,
            mb_strtoupper(trim((string) $entrega->numero_orden)),
            mb_strtoupper(trim((string) $entrega->linea_proceso)),
            mb_strtoupper(trim((string) $entrega->turno)),
        ]));
    }
}
