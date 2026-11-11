<?php

namespace App\Models;

use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FotoControlDespacho extends Model implements PerteneceATemporada
{
    use HasUuids, SoftDeletes;

    public const DELETED_AT = 'eliminada_at';

    protected $table = 'fotos_control_despacho';

    protected $guarded = [];

    public function control(): BelongsTo
    {
        return $this->belongsTo(ControlDespacho::class, 'control_despacho_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->control?->temporadaOperacionalId();
    }

    /** @return array<string, mixed> */
    public function representar(): array
    {
        $base = '/api/control-despacho/controles/'.$this->control_despacho_id.'/fotos/'.$this->id;

        return [...$this->only(['id', 'tipo', 'orden', 'mime', 'bytes', 'ancho', 'alto']),
            'subida_at' => $this->created_at?->toAtomString(),
            'url' => $base.'/original', 'miniatura_url' => $base.'/miniatura'];
    }

    protected function casts(): array
    {
        return ['orden' => 'integer', 'bytes' => 'integer', 'ancho' => 'integer', 'alto' => 'integer', 'eliminada_at' => 'datetime'];
    }
}
