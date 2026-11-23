<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'temporada_id', 'user_id', 'payload_hash', 'destinatario', 'guia', 'patente', 'despachado_at'])]
class DespachoNacionalPt extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'despachos_nacionales_pt';

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['despachado_at' => 'immutable_datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(PartidaNacionalPt::class, 'despacho_id');
    }
}
