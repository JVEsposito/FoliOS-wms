<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operacion_id' => $this->operacion_id,
            'plan_operacional_id' => $this->plan_operacional_id,
            'tarea_movimiento_id' => $this->tarea_movimiento_id,
            'tipo_movimiento' => $this->tipo_movimiento->value,
            'motivo' => $this->motivo,
            'folio' => [
                'id' => $this->folio_id,
                'numero_folio' => $this->whenLoaded(
                    'folio',
                    fn () => $this->folio->numero_folio,
                ),
                'tipo_bulto' => $this->whenLoaded(
                    'folio',
                    fn () => $this->folio->tipo_bulto->value,
                ),
            ],
            'origen' => $this->formatearExtremo('origen'),
            'destino' => $this->formatearExtremo('destino'),
            'usuario' => [
                'id' => $this->user_id,
                'nombre' => $this->whenLoaded('usuario', fn () => $this->usuario->name),
            ],
            'advertencias_confirmadas' => $this->advertencias_confirmadas ?? [],
            'generado_dispositivo_at' => $this->generado_dispositivo_at?->toAtomString(),
            'recibido_servidor_at' => $this->recibido_servidor_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatearExtremo(string $extremo): ?array
    {
        $camaraId = $this->{"camara_{$extremo}_id"};
        $posicionId = $this->{"posicion_{$extremo}_id"};

        if ($camaraId === null) {
            return null;
        }

        $camara = $this->{'camara'.ucfirst($extremo)};
        $posicion = $this->{'posicion'.ucfirst($extremo)};

        return [
            'camara' => [
                'id' => $camaraId,
                'codigo' => $camara?->codigo,
                'nombre' => $camara?->nombre,
            ],
            'posicion' => $posicionId ? [
                'id' => $posicionId,
                'banda' => $posicion?->banda,
                'posicion' => $posicion?->posicion,
                'nivel' => $posicion?->nivel,
                'etiqueta' => $posicion?->etiqueta,
            ] : null,
            'version_anterior' => $this->{"version_{$extremo}_anterior"},
            'version_resultante' => $this->{"version_{$extremo}_resultante"},
        ];
    }
}
