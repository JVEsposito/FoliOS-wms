<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SesionEstibaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'camara_id' => $this->camara_id,
            'tarea_movimiento_id' => $this->tarea_movimiento_id,
            'alcance' => $this->tarea_movimiento_id ? 'tarea' : 'camara',
            'estado' => $this->estado->value,
            'version_inicial' => $this->version_inicial,
            'version_final' => $this->version_final,
            'iniciada_at' => $this->iniciada_at?->toAtomString(),
            'ultima_actividad_at' => $this->ultima_actividad_at?->toAtomString(),
            'cerrada_at' => $this->cerrada_at?->toAtomString(),
            'motivo_cierre' => $this->motivo_cierre,
            'usuario' => [
                'id' => $this->user_id,
                'nombre' => $this->whenLoaded('usuario', fn () => $this->usuario->name),
            ],
            'dispositivo' => [
                'id' => $this->dispositivo_id,
                'nombre' => $this->whenLoaded(
                    'dispositivo',
                    fn () => $this->dispositivo->nombre,
                ),
            ],
        ];
    }
}
