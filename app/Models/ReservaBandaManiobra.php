<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'maniobra_operacional_id',
    'camara_id',
    'banda',
    'nivel',
    'clave_bloqueo',
    'reservada_at',
    'liberada_at',
    'motivo_liberacion',
    'sesion_estiba_id',
])]
class ReservaBandaManiobra extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'reservas_bandas_maniobra';

    public function maniobraOperacional(): BelongsTo
    {
        return $this->belongsTo(ManiobraOperacional::class);
    }

    public function camara(): BelongsTo
    {
        return $this->belongsTo(Camara::class);
    }

    protected function casts(): array
    {
        return [
            'banda' => 'integer',
            'nivel' => 'integer',
            'reservada_at' => 'datetime',
            'liberada_at' => 'datetime',
        ];
    }
}
