<?php

namespace App\Http\Resources;

use App\Models\UbicacionActual;
use App\Services\Camaras\EstadoEvacuacionEmergencia;
use Illuminate\Http\Request;

class CamaraPlanoResource extends CamaraResumenResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'emergencia' => $this->resource->getRelation('emergenciaActiva')
                ? app(EstadoEvacuacionEmergencia::class)->resumir($this->resource->getRelation('emergenciaActiva'))
                : null,
            'bandas_operacionales' => BandaOperacionalResource::collection(
                $this->whenLoaded('bandasOperacionales'),
            ),
            'folios_sin_posicion' => FolioSinPosicionResource::collection(
                $this->whenLoaded('ubicacionesSinPosicion'),
            ),
            'posiciones' => PosicionPlanoResource::collection(
                $this->whenLoaded('posiciones'),
            ),
            'registros_sin_cerrar' => $this->when(
                $this->resource->relationLoaded('posiciones'),
                fn (): int => $this->contarSinCerrar($request),
            ),
        ];
    }

    private function contarSinCerrar(Request $request): int
    {
        $ubicaciones = $this->posiciones
            ->flatMap(fn ($posicion) => $posicion->relationLoaded('ubicacionesActuales') ? $posicion->ubicacionesActuales : [])
            ->merge($this->resource->relationLoaded('ubicacionesSinPosicion') ? $this->ubicacionesSinPosicion : []);

        return $ubicaciones
            ->filter(fn (UbicacionActual $ubicacion): bool => $ubicacion->folio !== null
                && PosicionPlanoResource::registroSinCerrar($ubicacion->folio, $request) !== null)
            ->count();
    }
}
