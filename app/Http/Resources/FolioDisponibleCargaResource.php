<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolioDisponibleCargaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $posicion = $this->ubicacionActual?->posicion;
        $camara = $posicion?->camara;

        return [
            'id' => $this->id,
            'numero_folio' => $this->numero_folio,
            'tipo_bulto' => $this->tipo_bulto->value,
            'condicion_sag' => $this->whenLoaded('condicionSag', fn () => $this->condicionSag ? [
                'id' => $this->condicionSag->id,
                'codigo' => $this->condicionSag->codigo,
                'nombre' => $this->condicionSag->nombre,
            ] : null),
            'variedad' => $this->variedad,
            'calibre' => $this->calibre,
            'marca' => $this->marca,
            'exportadora' => $this->exportadora,
            'fecha_ingreso' => $this->fecha_ingreso?->toAtomString(),
            'ubicacion' => $posicion && $camara ? [
                'camara' => [
                    'id' => $camara?->id,
                    'codigo' => $camara?->codigo,
                    'nombre' => $camara?->nombre,
                ],
                'posicion' => [
                    'id' => $posicion?->id,
                    'etiqueta' => $posicion?->etiqueta,
                ],
            ] : null,
        ];
    }
}
