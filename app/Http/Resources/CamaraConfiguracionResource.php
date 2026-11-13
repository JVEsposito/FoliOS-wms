<?php

namespace App\Http\Resources;

use App\Enums\EstadoPosicion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CamaraConfiguracionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = $this->cantidad_bandas
            * $this->posiciones_por_banda
            * $this->cantidad_niveles;
        $activas = (int) ($this->posiciones_activas_count ?? 0);

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
            'dimensiones' => [
                'bandas' => $this->cantidad_bandas,
                'posiciones_por_banda' => $this->posiciones_por_banda,
                'niveles' => $this->cantidad_niveles,
            ],
            'capacidad' => [
                'total' => $total,
                'activas' => $activas,
                'fuera_servicio' => max(0, $total - $activas),
                'ocupadas' => (int) ($this->posiciones_ocupadas_count ?? 0),
            ],
            'bandas_operacionales' => BandaOperacionalResource::collection(
                $this->whenLoaded('bandasOperacionales'),
            ),
            'posiciones_fuera_servicio' => $this->whenLoaded(
                'posiciones',
                fn () => $this->posiciones
                    ->filter(fn ($posicion): bool => $posicion->estado !== EstadoPosicion::Activa)
                    ->map(fn ($posicion): array => [
                        'banda' => $posicion->banda,
                        'posicion' => $posicion->posicion,
                        'nivel' => $posicion->nivel,
                    ])
                    ->values(),
            ),
            'auditoria' => [
                'creado_por' => $this->whenLoaded('creadoPor', fn () => [
                    'id' => $this->creadoPor?->id,
                    'nombre' => $this->creadoPor?->name,
                ]),
                'actualizado_por' => $this->whenLoaded('actualizadoPor', fn () => [
                    'id' => $this->actualizadoPor?->id,
                    'nombre' => $this->actualizadoPor?->name,
                ]),
                'created_at' => $this->created_at?->toAtomString(),
                'updated_at' => $this->updated_at?->toAtomString(),
            ],
        ];
    }
}
