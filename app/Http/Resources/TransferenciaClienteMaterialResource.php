<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferenciaClienteMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'operacion_id' => $this->operacion_id, 'modalidad' => $this->modalidad,
            'folio_origen' => $this->folioOrigen->folio->only(['id', 'numero_folio', 'estado_operacional']),
            'folio_destino' => $this->folioDestino->folio->only(['id', 'numero_folio', 'estado_operacional']),
            'cliente_origen' => $this->clienteOrigen->only(['id', 'codigo', 'nombre']),
            'cliente_destino' => $this->clienteDestino->only(['id', 'codigo', 'nombre']),
            'item_origen' => $this->itemOrigen->only(['id', 'codigo', 'nombre']),
            'item_destino' => $this->itemDestino->only(['id', 'codigo', 'nombre']),
            'cantidad' => $this->cantidad, 'unidad_medida' => $this->unidad_medida, 'motivo' => $this->motivo,
            'documento_respaldo' => $this->documento_respaldo, 'usuario' => $this->usuario->only(['id', 'name']),
            'ocurrido_at' => $this->ocurrido_at->toIso8601String(), 'snapshot' => $this->snapshot];
    }
}
