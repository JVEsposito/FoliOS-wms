<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'temporada_id', 'numero', 'destinatario', 'estado', 'numero_guia_sii', 'observacion',
    'creado_por_user_id', 'confirmado_por_user_id', 'cancelado_por_user_id',
    'confirmado_at', 'cancelado_at',
])]
class DespachoComercialRetorno extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'despachos_comerciales_retorno';

    public function bins(): HasMany
    {
        return $this->hasMany(DespachoComercialRetornoBin::class, 'despacho_comercial_id');
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por_user_id');
    }

    protected function casts(): array
    {
        return ['confirmado_at' => 'datetime', 'cancelado_at' => 'datetime'];
    }
}
