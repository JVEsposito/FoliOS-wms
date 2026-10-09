<?php

namespace App\Http\Resources;

use App\Models\BultoRecepcionMaterial;
use App\Models\DetalleRecepcionMaterial;
use App\Models\EventoRecepcionMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecepcionMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'temporada' => $this->temporada ? [
                'id' => $this->temporada->id,
                'codigo' => $this->temporada->codigo,
                'nombre' => $this->temporada->nombre,
                'activa' => $this->temporada->activa,
            ] : null,
            'cliente' => $this->cliente ? [
                'id' => $this->cliente->id,
                'codigo' => $this->cliente->codigo,
                'codigo_folio_materiales' => $this->cliente->codigo_folio_materiales,
                'nombre' => $this->cliente->nombre,
            ] : null,
            'proveedor' => $this->proveedor ? [
                'id' => $this->proveedor->id,
                'codigo' => $this->proveedor->codigo,
                'nombre' => $this->proveedor->nombre,
            ] : null,
            'numero_guia_despacho' => $this->numero_guia_despacho,
            'fecha_documento' => $this->fecha_documento?->toDateString(),
            'orden_compra' => $this->orden_compra,
            'patente' => $this->patente,
            'transportista' => $this->transportista,
            'estado' => $this->estado->value,
            'version' => $this->version,
            'fotos_documento' => (int) ($this->resource->getAttribute('fotos_documento') ?? $this->fotos->where('tipo', 'documento')->count()),
            'fotos_referenciales' => (int) ($this->resource->getAttribute('fotos_referenciales') ?? $this->fotos->where('tipo', 'referencial')->count()),
            'puede_confirmar_por_fotos' => (int) ($this->resource->getAttribute('fotos_documento') ?? $this->fotos->where('tipo', 'documento')->count()) > 0,
            'fotos' => $this->whenLoaded('fotos', fn () => $this->fotos->map->representar()->values()),
            'observacion' => $this->observacion,
            'detalles_count' => $this->whenCounted('detalles'),
            'detalles' => $this->whenLoaded('detalles', fn () => $this->detalles
                ->map(fn (DetalleRecepcionMaterial $detalle): array => [
                    'id' => $detalle->id,
                    'item' => $detalle->item ? [
                        'id' => $detalle->item->id,
                        'codigo' => $detalle->item->codigo,
                        'nombre' => $detalle->item->nombre,
                    ] : null,
                    'categoria_operacional' => $detalle->categoria_operacional->value,
                    'categoria_operacional_etiqueta' => $detalle->categoria_operacional->etiqueta(),
                    'unidad_medida' => $detalle->unidad_medida,
                    'cantidad_documental' => $detalle->cantidad_documental,
                    'cantidad_contada' => $detalle->cantidad_contada,
                    'cantidad_aceptada' => $detalle->cantidad_recibida,
                    'cantidad_recibida' => $detalle->cantidad_recibida,
                    'cantidad_rechazada' => $detalle->cantidad_rechazada,
                    'observacion' => $detalle->observacion,
                    'bultos' => $detalle->relationLoaded('bultos')
                        ? $detalle->bultos->map(fn (BultoRecepcionMaterial $bulto): array => [
                            'id' => $bulto->id,
                            'cantidad' => $bulto->cantidad,
                            'lote_proveedor' => $bulto->lote_proveedor,
                            'fecha_fabricacion' => $bulto->fecha_fabricacion?->toDateString(),
                            'fecha_vencimiento' => $bulto->fecha_vencimiento?->toDateString(),
                            'bloqueado' => $bulto->bloqueado,
                            'motivo_bloqueo' => $bulto->motivo_bloqueo,
                            'folio' => $bulto->folioMaterial?->folio ? [
                                'id' => $bulto->folioMaterial->folio->id,
                                'numero_folio' => $bulto->folioMaterial->folio->numero_folio,
                                'estado_operacional' => $bulto->folioMaterial->folio->estado_operacional->value,
                                'ubicacion' => $bulto->folioMaterial->folio->ubicacionActual?->posicion ? [
                                    'camara' => $bulto->folioMaterial->folio->ubicacionActual->posicion->camara?->codigo,
                                    'posicion' => $bulto->folioMaterial->folio->ubicacionActual->posicion->etiqueta,
                                ] : null,
                            ] : null,
                        ])->values()
                        : [],
                ])->values()),
            'eventos' => $this->whenLoaded('eventos', fn () => $this->eventos
                ->map(fn (EventoRecepcionMaterial $evento): array => [
                    'id' => $evento->id,
                    'tipo' => $evento->tipo->value,
                    'operacion_id' => $evento->operacion_id,
                    'datos' => $evento->datos,
                    'observacion' => $evento->observacion,
                    'usuario' => $evento->usuario ? [
                        'id' => $evento->usuario->id,
                        'nombre' => $evento->usuario->name,
                    ] : null,
                    'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                ])->values()),
            'snapshot_confirmacion' => $this->snapshot_confirmacion,
            'creado_por' => $this->creadoPor ? [
                'id' => $this->creadoPor->id,
                'nombre' => $this->creadoPor->name,
            ] : null,
            'confirmado_por' => $this->confirmadoPor ? [
                'id' => $this->confirmadoPor->id,
                'nombre' => $this->confirmadoPor->name,
            ] : null,
            'confirmado_at' => $this->confirmado_at?->toAtomString(),
            'anulado_por' => $this->anuladoPor ? [
                'id' => $this->anuladoPor->id,
                'nombre' => $this->anuladoPor->name,
            ] : null,
            'anulado_at' => $this->anulado_at?->toAtomString(),
            'motivo_anulacion' => $this->motivo_anulacion,
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
