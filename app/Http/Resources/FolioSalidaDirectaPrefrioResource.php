<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolioSalidaDirectaPrefrioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $asignacion = $this->relationLoaded('procesosPrefrio')
            ? $this->procesosPrefrio
                ->filter(fn ($item): bool => $item->fechaResultado() !== null)
                ->sortByDesc(fn ($item) => $item->fechaResultado())
                ->first()
            : null;
        $proceso = $asignacion?->proceso;

        return [
            'id' => $this->id,
            'numero_folio' => $this->numero_folio,
            'tipo_bulto' => $this->tipo_bulto->value,
            'estado_operacional' => $this->estado_operacional->value,
            'condicion_termica' => $this->condicion_termica?->value,
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
            'prefrio' => $proceso ? [
                'id' => $proceso->id,
                'codigo' => $proceso->codigo,
                'finalizado_at' => $asignacion->fechaResultado()?->format(DATE_ATOM),
            ] : null,
        ];
    }
}
