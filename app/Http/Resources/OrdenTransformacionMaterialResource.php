<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenTransformacionMaterialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $unidadesPorFolio = data_get($this->snapshot_receta, 'salida.unidades_por_folio');
        $unidadesPorFolio = $unidadesPorFolio !== null
            ? round((float) $unidadesPorFolio, 3)
            : null;
        $foliosPlanificados = $unidadesPorFolio !== null && $unidadesPorFolio > 0
            ? (int) ceil((float) $this->cantidad_planificada_salida / $unidadesPorFolio)
            : null;
        $foliosGenerados = $this->relationLoaded('lotes')
            ? $this->lotes->filter(
                fn ($lote): bool => $lote->estado?->value === 'cerrado',
            )->count()
            : null;

        return [
            'id' => $this->id,
            'estado' => $this->estado->value,
            'version' => $this->version,
            'faltantes_por_vencimiento' => $this->faltantes_por_vencimiento,
            'alerta_vencimiento' => $this->faltantes_por_vencimiento ? 'Reserva insuficiente por vencimiento' : null,
            'cantidad_planificada_salida' => $this->cantidad_planificada_salida,
            'cantidad_real_salida' => $this->cantidad_real_salida,
            'unidades_por_folio_salida' => $unidadesPorFolio !== null
                ? number_format($unidadesPorFolio, 3, '.', '')
                : null,
            'folios_planificados' => $foliosPlanificados,
            'folios_generados' => $this->when(
                $foliosGenerados !== null,
                fn (): int => $foliosGenerados,
            ),
            'folios_pendientes' => $this->when(
                $foliosGenerados !== null && $foliosPlanificados !== null,
                fn (): int => max(0, $foliosPlanificados - $foliosGenerados),
            ),
            'linea' => $this->linea,
            'turno' => $this->turno,
            'fecha_operacional' => $this->fecha_operacional?->toDateString(),
            'observacion' => $this->observacion,
            'motivo_cancelacion' => $this->motivo_cancelacion,
            'receta_snapshot' => $this->when(
                $this->relationLoaded('reservas')
                    && $this->relationLoaded('lotes')
                    && $this->relationLoaded('eventos'),
                fn (): ?array => $this->snapshot_receta,
            ),
            'reservas_count' => $this->whenCounted('reservas'),
            'lotes_count' => $this->whenCounted('lotes'),
            'tiene_salidas' => $this->when(
                array_key_exists('tiene_salidas', $this->resource->getAttributes()),
                fn (): bool => (bool) $this->tiene_salidas,
            ),
            'temporada' => $this->whenLoaded('temporada', fn (): array => [
                'id' => $this->temporada->id,
                'codigo' => $this->temporada->codigo,
                'nombre' => $this->temporada->nombre,
                'activa' => $this->temporada->activa,
            ]),
            'cliente' => $this->whenLoaded('cliente', fn (): array => [
                'id' => $this->cliente->id,
                'codigo' => $this->cliente->codigo,
                'nombre' => $this->cliente->nombre,
            ]),
            'version_receta' => $this->whenLoaded('versionReceta', fn (): array => [
                'id' => $this->versionReceta->id,
                'numero_version' => $this->versionReceta->numero_version,
                'estado' => $this->versionReceta->estado->value,
                'receta' => [
                    'id' => $this->versionReceta->receta->id,
                    'nombre' => $this->versionReceta->receta->nombre,
                    'item_salida' => [
                        'id' => $this->versionReceta->receta->itemSalida->id,
                        'codigo' => $this->versionReceta->receta->itemSalida->codigo,
                        'nombre' => $this->versionReceta->receta->itemSalida->nombre,
                        'unidad_medida' => $this->versionReceta->receta->itemSalida->unidad_medida,
                    ],
                ],
            ]),
            'reservas' => $this->whenLoaded('reservas', fn () => $this->reservas->map(
                fn ($reserva): array => [
                    'id' => $reserva->id,
                    'estado' => $reserva->estado->value,
                    'cantidad' => $reserva->cantidad,
                    'cantidad_consumida' => $reserva->cantidad_consumida,
                    'cantidad_pendiente' => number_format(max(
                        0,
                        round((float) $reserva->cantidad - (float) $reserva->cantidad_consumida, 3),
                    ), 3, '.', ''),
                    'orden_fifo' => $reserva->orden_fifo,
                    'item_material_id' => $reserva->item_material_id,
                    'folio' => $reserva->relationLoaded('folioMaterial') ? [
                        'id' => $reserva->folio_id,
                        'numero_folio' => $reserva->folioMaterial->folio->numero_folio,
                        'cantidad_actual' => $reserva->folioMaterial->cantidad_actual,
                        'cantidad_reservada' => $reserva->folioMaterial->cantidad_reservada,
                        'ubicacion' => $reserva->folioMaterial->folio->ubicacionActual?->posicion ? [
                            'camara' => $reserva->folioMaterial->folio->ubicacionActual->posicion->camara->codigo,
                            'posicion' => $reserva->folioMaterial->folio->ubicacionActual->posicion->etiqueta,
                        ] : null,
                    ] : null,
                ],
            )->values()),
            'lotes' => $this->whenLoaded('lotes', fn () => $this->lotes->map(
                fn ($lote): array => [
                    'id' => $lote->id,
                    'numero_lote' => $lote->numero_lote,
                    'estado' => $lote->estado->value,
                    'cantidad_planificada_salida' => $lote->cantidad_planificada_salida,
                    'cantidad_real_salida' => $lote->cantidad_real_salida,
                    'salida_teorica' => $lote->salida_teorica,
                    'merma_estandar' => $lote->merma_estandar,
                    'merma_real' => $lote->merma_real,
                    'desviacion_merma' => $lote->desviacion_merma,
                    'iniciado_at' => $lote->iniciado_at?->toAtomString(),
                    'cerrado_at' => $lote->cerrado_at?->toAtomString(),
                    'reversado_at' => $lote->reversado_at?->toAtomString(),
                    'motivo_reversa' => $lote->motivo_reversa,
                    'reversado_por' => $lote->relationLoaded('reversadoPor')
                        && $lote->reversadoPor ? [
                            'id' => $lote->reversadoPor->id,
                            'nombre' => $lote->reversadoPor->name,
                        ] : null,
                    'consumos' => $lote->relationLoaded('consumos')
                        ? $lote->consumos->map(fn ($consumo): array => [
                            'id' => $consumo->id,
                            'folio_id' => $consumo->folio_id,
                            'numero_folio' => $consumo->folioMaterial->folio->numero_folio,
                            'item' => [
                                'id' => $consumo->item->id,
                                'codigo' => $consumo->item->codigo,
                                'nombre' => $consumo->item->nombre,
                                'unidad_medida' => $consumo->item->unidad_medida,
                            ],
                            'cantidad_consumida' => $consumo->cantidad_consumida,
                            'cantidad_anterior' => $consumo->cantidad_anterior,
                            'cantidad_resultante' => $consumo->cantidad_resultante,
                            'siguio_fifo' => $consumo->siguio_fifo,
                            'motivo_desviacion_fifo' => $consumo->motivo_desviacion_fifo,
                            'ocurrido_at' => $consumo->ocurrido_at?->toAtomString(),
                        ])->values()
                        : [],
                    'salidas' => $lote->relationLoaded('salidas')
                        ? $lote->salidas->map(fn ($salida): array => [
                            'id' => $salida->id,
                            'folio_id' => $salida->folio_id,
                            'numero_folio' => $salida->folioMaterial->folio->numero_folio,
                            'item' => [
                                'id' => $salida->item->id,
                                'codigo' => $salida->item->codigo,
                                'nombre' => $salida->item->nombre,
                                'unidad_medida' => $salida->item->unidad_medida,
                            ],
                            'cantidad_producida' => $salida->cantidad_producida,
                            'es_salida_principal' => $salida->es_salida_principal,
                        ])->values()
                        : [],
                ],
            )->values()),
            'eventos' => $this->whenLoaded('eventos', fn () => $this->eventos->map(
                fn ($evento): array => [
                    'id' => $evento->id,
                    'tipo' => $evento->tipo->value,
                    'datos' => $evento->datos,
                    'observacion' => $evento->observacion,
                    'usuario' => $evento->relationLoaded('usuario') ? [
                        'id' => $evento->usuario->id,
                        'nombre' => $evento->usuario->name,
                    ] : null,
                    'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                ],
            )->values()),
            'creado_por' => $this->whenLoaded('creadoPor', fn (): array => [
                'id' => $this->creadoPor->id,
                'nombre' => $this->creadoPor->name,
            ]),
            'iniciado_at' => $this->iniciado_at?->toAtomString(),
            'cerrado_at' => $this->cerrado_at?->toAtomString(),
            'cancelado_at' => $this->cancelado_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
