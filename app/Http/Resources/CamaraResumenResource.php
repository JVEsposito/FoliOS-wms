<?php

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CamaraResumenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = (int) ($this->posiciones_count ?? 0);
        $ocupadas = (int) ($this->posiciones_ocupadas_count ?? 0);

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'contenido' => $this->contenido->value,
            'estado' => $this->estado->value,
            'version_plano' => $this->version_plano,
            'prioridad_operacional' => $this->prioridad_operacional?->value ?? 'general',
            'version_prioridad' => (int) ($this->version_prioridad ?? 1),
            'sentido_numeracion_bandas' => $this->sentido_numeracion_bandas->value,
            'ocupacion' => [
                'ocupadas' => $ocupadas,
                'sin_posicion' => (int) ($this->ubicaciones_sin_posicion_count ?? 0),
                'total' => $total,
                'porcentaje' => $total > 0
                    ? round(($ocupadas / $total) * 100, 1)
                    : 0.0,
            ],
            'acceso' => $this->formatearAcceso($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatearAcceso(Request $request): array
    {
        $bloqueo = $this->resource->relationLoaded('bloqueo')
            ? $this->bloqueo
            : null;

        if (! $bloqueo) {
            return [
                'modo' => 'disponible',
                'bloqueada' => false,
                'sesion' => null,
            ];
        }

        $sesion = $bloqueo->relationLoaded('sesionEstiba')
            ? $bloqueo->sesionEstiba
            : null;
        $token = $request->user()?->currentAccessToken();
        $dispositivoId = $token instanceof PersonalAccessToken
            ? $token->dispositivo_id
            : null;
        $esPropia = $sesion
            && $sesion->user_id === $request->user()?->id
            && $sesion->dispositivo_id === $dispositivoId;

        return [
            'modo' => $esPropia ? 'edicion' : 'solo_lectura',
            'bloqueada' => true,
            'sesion' => $sesion ? [
                'id' => $sesion->id,
                'es_propia' => $esPropia,
                'usuario' => [
                    'id' => $sesion->usuario?->id,
                    'nombre' => $sesion->usuario?->name,
                ],
                'dispositivo' => [
                    'id' => $sesion->dispositivo?->id,
                    'nombre' => $sesion->dispositivo?->nombre,
                ],
                'iniciada_at' => $sesion->iniciada_at?->toAtomString(),
                'ultima_actividad_at' => $sesion->ultima_actividad_at?->toAtomString(),
            ] : null,
        ];
    }
}
