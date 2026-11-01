<?php

namespace App\Http\Resources;

use App\Models\EntregaFrutaProceso;
use App\Models\RetornoPacking;
use App\Models\SubloteRetornoPacking;
use App\Services\MateriaPrima\ServicioFrutaProceso;
use App\Services\MateriaPrima\ServicioRetornoPacking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FrutaProcesoLoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entregas = $this->relationLoaded('entregasProceso')
            ? $this->entregasProceso->sortByDesc(fn (EntregaFrutaProceso $entrega): string => implode('|', [
                $entrega->entregado_at?->format('Y-m-d H:i:s.u') ?? '',
                $entrega->created_at?->format('Y-m-d H:i:s.u') ?? '',
                $entrega->id,
            ]))->values()
            : collect();
        $vigentes = $entregas->whereNull('anulado_at');
        $ultimaVigente = $vigentes->first();
        $entregados = (int) $vigentes->sum('cantidad_envases');
        $total = (int) $this->cantidad_envases_primarios;
        $disponibles = max(0, $total - $entregados);
        $servicioEntrega = app(ServicioFrutaProceso::class);
        $servicioRetorno = app(ServicioRetornoPacking::class);

        return [
            'id' => $this->id,
            'numero_lote' => $this->numero_lote,
            'estado' => $this->estado->value,
            'version' => $this->version,
            'cliente' => $this->cliente ? [
                'id' => $this->cliente->id,
                'codigo' => $this->cliente->codigo,
                'nombre' => $this->cliente->nombre,
            ] : null,
            'recepcion' => $this->recepcion ? [
                'id' => $this->recepcion->id,
                'numero_recepcion' => $this->recepcion->numero_recepcion,
                'numero_guia_despacho' => $this->recepcion->numero_guia_despacho,
            ] : null,
            'producto' => [
                'especie' => $this->especie_snapshot,
                'variedad' => $this->variedad_snapshot,
                'calibre' => $this->calibre_snapshot,
                'csg' => $this->csg_snapshot,
                'predio' => $this->predio,
                'cuartel' => $this->cuartel,
                'tipo' => $this->tipo_producto->value,
            ],
            'camara' => $this->asignacionCamara?->camara ? [
                'id' => $this->asignacionCamara->camara->id,
                'codigo' => $this->asignacionCamara->camara->codigo,
                'nombre' => $this->asignacionCamara->camara->nombre,
            ] : null,
            'origen_operacional' => $this->asignacionCamara?->camara
                ? 'camara_materia_prima'
                : 'hidrocooler_directo',
            'envase_primario' => $this->envase_primario->value,
            'progreso' => [
                'total' => $total,
                'entregados' => $entregados,
                'disponibles' => $disponibles,
                'porcentaje' => $total > 0 ? round(($entregados / $total) * 100, 1) : 0,
            ],
            'ordenes' => $this->relationLoaded('reservasOrdenProceso')
                ? $this->reservasOrdenProceso
                    ->filter(fn ($reserva): bool => $reserva->bins_pendientes > 0 && (bool) $reserva->orden?->estado?->abierta())
                    ->map(fn ($reserva): array => [
                        'id' => $reserva->orden->id,
                        'codigo' => $reserva->orden->codigo,
                        'fecha' => $reserva->orden->fecha?->toDateString(),
                        'linea' => $reserva->orden->linea?->only(['id', 'codigo', 'nombre']),
                        'version_id' => $reserva->orden->version_vigente_id,
                        'numero_version' => $reserva->orden->numero_version_vigente,
                        'bins_pendientes' => $reserva->bins_pendientes,
                    ])->values()
                : [],
            'entregas' => $entregas->map(
                fn (EntregaFrutaProceso $entrega): array => $this->entrega(
                    $entrega,
                    $request,
                    $servicioEntrega,
                    $servicioRetorno,
                    $ultimaVigente?->id,
                ),
            )->values(),
            'ultima_entrega_at' => $ultimaVigente?->entregado_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function entrega(
        EntregaFrutaProceso $entrega,
        Request $request,
        ServicioFrutaProceso $servicioEntrega,
        ServicioRetornoPacking $servicioRetorno,
        ?string $ultimaEntregaVigenteId,
    ): array {
        $retornos = $entrega->relationLoaded('retornos')
            ? $entrega->retornos->sortByDesc(fn (RetornoPacking $retorno): string => implode('|', [
                $retorno->registrado_at?->format('Y-m-d H:i:s.u') ?? '',
                $retorno->created_at?->format('Y-m-d H:i:s.u') ?? '',
                $retorno->id,
            ]))->values()
            : collect();
        $retornosVigentes = $retornos->whereNull('anulado_at');
        $ultimoRetornoVigente = $retornosVigentes->first();
        $cerrado = $retornosVigentes->contains(
            fn (RetornoPacking $retorno): bool => $this->cierraEntrega(
                $retorno,
                $entrega,
            ),
        );
        $resultadosVigentes = $retornosVigentes->flatMap(
            fn (RetornoPacking $retorno) => $retorno->resultados,
        );
        $binsRetornados = (int) $resultadosVigentes->sum('cantidad_bins');
        $kilosRecuperados = $resultadosVigentes->contains(
            fn (SubloteRetornoPacking $sublote): bool => $sublote->kilos_netos !== null,
        )
            ? round((float) $resultadosVigentes->sum(
                fn (SubloteRetornoPacking $sublote): float => (float) ($sublote->kilos_netos ?? 0),
            ), 3)
            : null;
        $kilosEnviados = $entrega->kilos_enviados !== null
            ? (float) $entrega->kilos_enviados
            : null;

        return [
            'id' => $entrega->id,
            'cantidad_envases' => $entrega->cantidad_envases,
            'kilos_enviados' => $kilosEnviados,
            'saldo_anterior' => $entrega->saldo_anterior,
            'saldo_posterior' => $entrega->saldo_posterior,
            'linea_proceso' => $entrega->linea_proceso,
            'turno' => $entrega->turno,
            'numero_orden' => $entrega->numero_orden,
            'orden_proceso' => $entrega->orden_proceso_mp_id ? [
                'id' => $entrega->orden_proceso_mp_id,
                'codigo' => $entrega->ordenProceso?->codigo,
                'fuera_de_orden' => (bool) $entrega->fuera_de_orden,
                'autorizado_por' => $entrega->autorizadoPor?->name,
            ] : null,
            'observacion' => $entrega->observacion,
            'entregado_por' => $entrega->entregadoPor ? [
                'id' => $entrega->entregadoPor->id,
                'nombre' => $entrega->entregadoPor->name,
            ] : null,
            'dispositivo' => $entrega->dispositivo ? [
                'id' => $entrega->dispositivo->id,
                'codigo' => $entrega->dispositivo->codigo,
                'nombre' => $entrega->dispositivo->nombre,
            ] : null,
            'entregado_at' => $entrega->entregado_at?->toAtomString(),
            'anulado' => $entrega->anulado_at !== null,
            'anulado_por' => $entrega->anuladoPor?->name,
            'anulado_at' => $entrega->anulado_at?->toAtomString(),
            'motivo_anulacion' => $entrega->motivo_anulacion,
            'puede_anular' => $request->user()
                ? $servicioEntrega->puedeAnular(
                    $entrega,
                    $this->resource,
                    $request->user(),
                    $ultimaEntregaVigenteId,
                )
                : false,
            'retorno' => [
                'estado' => $cerrado
                    ? 'completado'
                    : ($retornosVigentes->isEmpty() ? 'pendiente' : 'parcial'),
                'bins_retornados' => $binsRetornados,
                'kilos_recuperados' => $kilosRecuperados,
                'merma_kilos' => $cerrado
                    && $kilosEnviados !== null
                    && $kilosRecuperados !== null
                    ? round($kilosEnviados - $kilosRecuperados, 3)
                    : null,
                'puede_registrar' => $request->user()
                    ? $servicioRetorno->puedeRegistrar($entrega, $request->user())
                    : false,
                'movimientos' => $retornos->map(
                    fn (RetornoPacking $retorno): array => $this->retorno(
                        $retorno,
                        $entrega,
                        $request,
                        $servicioRetorno,
                        $ultimoRetornoVigente?->id,
                    ),
                )->values(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function retorno(
        RetornoPacking $retorno,
        EntregaFrutaProceso $entrega,
        Request $request,
        ServicioRetornoPacking $servicio,
        ?string $ultimoRetornoVigenteId,
    ): array {
        return [
            'id' => $retorno->id,
            'numero' => $retorno->numero,
            'cierra_entrega' => $this->cierraEntrega($retorno, $entrega),
            'origenes' => $retorno->relationLoaded('entregas')
                ? $retorno->entregas->map(fn (EntregaFrutaProceso $origen): array => [
                    'entrega_id' => $origen->id,
                    'lote_id' => $origen->lote_materia_prima_id,
                    'numero_lote' => $origen->lote?->numero_lote,
                    'linea_proceso' => $origen->linea_proceso,
                    'turno' => $origen->turno,
                    'numero_orden' => $origen->numero_orden,
                    'cierra_entrega' => (bool) $origen->pivot->cierra_entrega,
                ])->values()
                : collect(),
            'observacion' => $retorno->observacion,
            'registrado_por' => $retorno->registradoPor ? [
                'id' => $retorno->registradoPor->id,
                'nombre' => $retorno->registradoPor->name,
            ] : null,
            'dispositivo' => $retorno->dispositivo ? [
                'id' => $retorno->dispositivo->id,
                'codigo' => $retorno->dispositivo->codigo,
                'nombre' => $retorno->dispositivo->nombre,
            ] : null,
            'registrado_at' => $retorno->registrado_at?->toAtomString(),
            'anulado' => $retorno->anulado_at !== null,
            'anulado_por' => $retorno->anuladoPor?->name,
            'anulado_at' => $retorno->anulado_at?->toAtomString(),
            'motivo_anulacion' => $retorno->motivo_anulacion,
            'puede_anular' => $request->user()
                ? $servicio->puedeAnular(
                    $retorno,
                    $request->user(),
                    $ultimoRetornoVigenteId,
                )
                : false,
            'resultados' => $retorno->resultados
                ->sortBy('numero_sublote')
                ->map(fn (SubloteRetornoPacking $sublote): array => [
                    'id' => $sublote->id,
                    'numero_sublote' => $sublote->numero_sublote,
                    'tipo' => [
                        'id' => $sublote->tipoResultado?->id,
                        'codigo' => $sublote->tipoResultado?->codigo,
                        'nombre' => $sublote->tipoResultado?->nombre,
                    ],
                    'nombre_resultado' => $sublote->nombre_resultado,
                    'cantidad_bins' => $sublote->cantidad_bins,
                    'kilos_netos' => $sublote->kilos_netos !== null
                        ? (float) $sublote->kilos_netos
                        : null,
                    'estado' => $sublote->estado->value,
                    'camara' => $sublote->camara ? [
                        'id' => $sublote->camara->id,
                        'codigo' => $sublote->camara->codigo,
                        'nombre' => $sublote->camara->nombre,
                    ] : null,
                    'ubicado_por' => $sublote->ubicadoPor?->name,
                    'ubicado_at' => $sublote->ubicado_at?->toAtomString(),
                    'observacion_ubicacion' => $sublote->observacion_ubicacion,
                    'puede_ubicar' => $request->user()
                        ? $servicio->puedeUbicar($sublote, $request->user())
                        : false,
                ])
                ->values(),
        ];
    }

    private function cierraEntrega(
        RetornoPacking $retorno,
        EntregaFrutaProceso $entrega,
    ): bool {
        if ($retorno->pivot
            && $retorno->pivot->entrega_fruta_proceso_id === $entrega->id) {
            return (bool) $retorno->pivot->cierra_entrega;
        }

        return $retorno->entrega_fruta_proceso_id === $entrega->id
            && (bool) $retorno->cierra_entrega;
    }
}
