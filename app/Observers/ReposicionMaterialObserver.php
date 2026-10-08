<?php

namespace App\Observers;

use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Services\Materiales\NivelesStockMaterial;
use App\Services\Materiales\ServicioReposicionMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReposicionMaterialObserver
{
    public function saving(Model $item): void
    {
        if ($item instanceof ItemMaterial) {
            NivelesStockMaterial::validar($item->only(NivelesStockMaterial::CAMPOS));
        }
    }

    public function created(Model $modelo): void
    {
        if ($modelo instanceof ItemMaterial && collect(NivelesStockMaterial::CAMPOS)->contains(fn ($c) => $modelo->$c !== null)) {
            $this->auditar($modelo, array_fill_keys(NivelesStockMaterial::CAMPOS, null));
        }
    }

    public function updated(Model $modelo): void
    {
        if ($modelo instanceof ItemMaterial && $modelo->wasChanged(NivelesStockMaterial::CAMPOS)) {
            $this->auditar($modelo, collect(NivelesStockMaterial::CAMPOS)->mapWithKeys(fn ($c) => [$c => $modelo->getRawOriginal($c)])->all());
        }
    }

    private function auditar(ItemMaterial $item, array $anteriores): void
    {
        DB::table('cambios_niveles_stock_materiales')->insert(['id' => (string) Str::uuid(), 'item_material_id' => $item->id,
            'user_id' => $item->actualizado_por_user_id, 'anteriores' => json_encode($anteriores),
            'nuevos' => json_encode($item->only(NivelesStockMaterial::CAMPOS)), 'ocurrido_at' => now()]);
    }

    public function saved(Model $modelo): void
    {
        if ($modelo instanceof ItemMaterial) {
            $ids = [$modelo->id];
        } elseif ($modelo instanceof SaldoMaterialAlmacen) {
            $ids = [FolioMaterial::whereKey($modelo->folio_id)->value('item_material_id')];
        } else {
            $ids = [$modelo->item_material_id, $modelo instanceof FolioMaterial ? $modelo->getRawOriginal('item_material_id') : null];
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            // Tras el commit se observan también reservas y el kardex completo.
            // La revisión del ítem serializa las transiciones y sus avisos.
            DB::afterCommit(fn () => app(ServicioReposicionMaterial::class)->recalcularItem($id));
        }
    }
}
