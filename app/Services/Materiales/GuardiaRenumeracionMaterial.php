<?php

namespace App\Services\Materiales;

use App\Models\FolioMaterial;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\VerificacionUbicacionItem;
use DomainException;

/** Renumerar rompe la identidad contada; los movimientos ordinarios siguen permitidos. */
class GuardiaRenumeracionMaterial
{
    public function asegurar(FolioMaterial $folio, ?string $posicionId, string $temporadaId): void
    {
        $tomas = TomaInventarioMaterial::where('temporada_id', $temporadaId)
            ->whereIn('estado', ['en_conteo', 'en_revision'])->get();
        foreach ($tomas as $toma) {
            if (($posicionId && $toma->posiciones()->where('posicion_id', $posicionId)->exists())
                || collect($toma->foto ?? [])->contains(fn ($s) => ($s['folio_id'] ?? null) === $folio->folio_id)
                || TomaInventarioMaterialResultado::whereHas('tarea', fn ($q) => $q->where('toma_id', $toma->id))->where('folio_id', $folio->folio_id)->exists()) {
                throw new DomainException('El folio o su posición participa en una toma de inventario abierta. Finaliza la toma antes de transferir.');
            }
        }
        $items = VerificacionUbicacionItem::whereHas('ronda', fn ($q) => $q->where('temporada_id', $temporadaId)->where('estado', 'pendiente'))
            ->where('resultado', '!=', 'no_aplica')->get();
        foreach ($items as $item) {
            if (($posicionId && $item->posicion_id === $posicionId)
                || collect($item->snapshot_materiales['saldos'] ?? [])->contains(fn ($s) => ($s['folio_id'] ?? null) === $folio->folio_id)) {
                throw new DomainException('El folio o su posición participa en una verificación abierta. Finaliza la ronda antes de transferir.');
            }
        }
    }
}
