<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo', 'nombre', 'version', 'fecha_vigencia', 'localidad', 'activo',
    'creado_por_user_id', 'actualizado_por_user_id',
])]
class FormatoRegistro extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'formatos_registro';

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoFormatoRegistro::class);
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'fecha_vigencia' => 'immutable_date'];
    }
}
