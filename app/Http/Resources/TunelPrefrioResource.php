<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TunelPrefrioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'capacidad_posiciones' => $this->capacidad_posiciones,
            'setpoint_habitual' => $this->setpoint_habitual !== null
                ? (float) $this->setpoint_habitual
                : null,
            'estado_administrativo' => $this->estado_administrativo->value,
            'estado_tecnico' => $this->estado_tecnico->value,
            'codigo_externo' => $this->codigo_externo,
            'observacion' => $this->observacion,
            'version_configuracion' => $this->version_configuracion,
            'posiciones' => $this->whenLoaded('posiciones', fn () => $this->posiciones->map(fn ($posicion) => [
                'id' => $posicion->id,
                'numero' => $posicion->numero,
                'etiqueta' => $posicion->etiqueta,
                'activa' => $posicion->activa,
            ])->values()),
            'proceso_activo' => $this->whenLoaded('procesoActivo', fn () => $this->procesoActivo ? [
                'id' => $this->procesoActivo->id,
                'codigo' => $this->procesoActivo->codigo,
                'estado' => $this->procesoActivo->estado->value,
                'version' => $this->procesoActivo->version,
                'folios_cargados' => $this->procesoActivo->folios->filter(fn ($asignacion): bool => $asignacion->ocupaPosicion()
                    && ($this->procesoActivo->estado->esActivo() || $asignacion->finalizado_parcial_at !== null))->count(),
                'iniciado_at' => $this->procesoActivo->iniciado_at?->toAtomString(),
            ] : null),
            'creado_por' => $this->whenLoaded('creadoPor', fn () => [
                'id' => $this->creadoPor->id,
                'nombre' => $this->creadoPor->name,
            ]),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
