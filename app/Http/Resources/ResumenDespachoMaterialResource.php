<?php

namespace App\Http\Resources;

use App\Services\Materiales\RegistroDespachoMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResumenDespachoMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'temporada' => $this->whenLoaded('temporada', fn () => $this->temporada ? [
                'id' => $this->temporada->id,
                'codigo' => $this->temporada->codigo,
                'nombre' => $this->temporada->nombre,
                'activa' => $this->temporada->activa,
            ] : null),
            'codigo' => $this->codigo,
            'almacen_origen_id' => $this->almacen_origen_id,
            'almacen_origen' => $this->whenLoaded('almacenOrigen', fn () => $this->almacenOrigen ? ['id' => $this->almacenOrigen->id, 'codigo' => $this->almacenOrigen->codigo, 'nombre' => $this->almacenOrigen->nombre] : null),
            'origen' => $this->origen->value,
            'modalidad' => $this->modalidad,
            'estado' => $this->estado->value,
            'asignado_a_user_id' => $this->asignado_a_user_id,
            'destino' => [
                'id' => $this->destino_material_id,
                'nombre' => $this->destino_nombre,
                'centro_costo' => $this->destino_centro_costo,
            ],
            'observacion' => $this->observacion,
            'items' => $this->whenLoaded('detalles', fn () => $this->detalles->map(
                function ($detalle): array {
                    $reservado = (float) ($detalle->cantidad_reservada_resumen ?? 0);

                    return [
                        'detalle_id' => $detalle->id,
                        'alerta_vencimiento' => (float) $detalle->cantidad_sin_reserva_por_vencimiento > 0 ? 'Reserva insuficiente por vencimiento' : null,
                        'item' => [
                            'id' => $detalle->item->id,
                            'cliente' => [
                                'id' => $detalle->item->cliente->id,
                                'temporada' => [
                                    'id' => $detalle->item->cliente->temporada->id,
                                    'codigo' => $detalle->item->cliente->temporada->codigo,
                                    'nombre' => $detalle->item->cliente->temporada->nombre,
                                    'activa' => $detalle->item->cliente->temporada->activa,
                                ],
                                'codigo' => $detalle->item->cliente->codigo,
                                'nombre' => $detalle->item->cliente->nombre,
                                'activo' => $detalle->item->cliente->activo,
                            ],
                            'codigo' => $detalle->item->codigo,
                            'nombre' => $detalle->item->nombre,
                            'categoria' => $detalle->item->categoria,
                        ],
                        'cantidad_solicitada' => $detalle->cantidad_solicitada,
                        'cantidad_despachada' => $detalle->cantidad_despachada,
                        'cantidad_pendiente' => number_format(max(
                            0,
                            (float) $detalle->cantidad_solicitada
                                - (float) $detalle->cantidad_despachada,
                        ), 3, '.', ''),
                        'cantidad_reservada' => number_format($reservado, 3, '.', ''),
                        'unidad_medida' => $detalle->unidad_medida,
                    ];
                },
            )),
            'entregas' => $this->whenLoaded('operacionesRetiro', fn () => $this->operacionesRetiro->map(fn ($entrega) => [
                ...app(RegistroDespachoMaterial::class)->representar($entrega),
                'retiros' => $entrega->relationLoaded('retiros') ? $entrega->retiros->map->only(['id', 'folio_id', 'cantidad_retirada'])->values()->all() : [],
                'regularizaciones' => $entrega->relationLoaded('regularizaciones') ? $entrega->regularizaciones->map(fn ($evento) => [
                    'id' => $evento->id, 'antes' => $evento->antes, 'despues' => $evento->despues, 'motivo' => $evento->motivo,
                    'usuario' => $evento->usuario?->only(['id', 'name']), 'fuera_plazo' => $evento->fuera_plazo, 'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                ])->values()->all() : [],
            ])->values()),
            'completado_at' => $this->completado_at?->toAtomString(),
            'cancelado_at' => $this->cancelado_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
