<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FotoItemMaterial extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fotos_items_materiales';

    protected $guarded = ['principal_item_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    protected function casts(): array
    {
        return ['principal' => 'boolean', 'orden' => 'integer', 'subida_at' => 'datetime'];
    }

    public function representar(): array
    {
        return ['id' => $this->id, 'mime' => $this->mime, 'tamano_bytes' => $this->tamano_bytes,
            'ancho' => $this->ancho, 'alto' => $this->alto, 'orden' => $this->orden, 'principal' => $this->principal,
            'subida_at' => $this->subida_at?->toAtomString(),
            'url' => '/api/materiales/fotos-items/'.$this->id.'/archivo',
            'miniatura_url' => '/api/materiales/fotos-items/'.$this->id.'/miniatura'];
    }
}
