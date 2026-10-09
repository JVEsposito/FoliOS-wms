<?php

namespace App\Models;

use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FotoRecepcionMaterial extends Model implements PerteneceATemporada
{
    use HasUuids, SoftDeletes;

    public const DELETED_AT = 'eliminada_at';

    protected $table = 'fotos_recepciones_materiales';

    protected $guarded = [];

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionMaterial::class, 'recepcion_material_id');
    }

    protected function casts(): array
    {
        return ['orden' => 'integer', 'bytes' => 'integer', 'eliminada_at' => 'datetime'];
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->recepcion?->temporada_id;
    }

    public function representar(): array
    {
        $base = '/api/materiales/recepciones/'.$this->recepcion_material_id.'/fotos/'.$this->id;

        return [...$this->only(['id', 'tipo', 'orden', 'mime', 'bytes', 'ancho', 'alto', 'sha256']),
            'subida_at' => $this->created_at?->toAtomString(),
            'url' => $base.'/original', 'miniatura_url' => $base.'/miniatura'];
    }
}
