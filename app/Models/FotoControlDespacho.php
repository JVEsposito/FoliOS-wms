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

    public function subidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subida_por_user_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->control?->temporadaOperacionalId();
    }

    public function estaArchivada(): bool
    {
        return $this->archivada_at !== null;
    }

    /** @return array<string, mixed> */
    public function representar(): array
    {
        $base = '/api/control-despacho/controles/'.$this->control_despacho_id.'/fotos/'.$this->id;
        $archivada = $this->estaArchivada();

        return [...$this->only(['id', 'tipo', 'orden', 'bytes', 'ancho', 'alto']),
            'subida_at' => $this->created_at?->toAtomString(),
            'archivada' => $archivada,
            'pagina_pdf' => $this->pdf_pagina,
            'url' => $archivada ? null : $base.'/original',
            'miniatura_url' => $archivada ? null : $base.'/miniatura'];
    }

    protected function casts(): array
    {
        return [
            'orden' => 'integer', 'bytes' => 'integer', 'bytes_recibidos' => 'integer', 'ancho' => 'integer', 'alto' => 'integer',
            'pdf_pagina' => 'integer', 'pdf_offset' => 'integer', 'pdf_longitud' => 'integer',
            'archivada_at' => 'datetime', 'eliminada_at' => 'datetime',
        ];
    }
}
