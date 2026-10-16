<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'credenciales', 'activa', 'version', 'ultima_prueba_estado', 'ultima_prueba_at', 'creado_por_user_id', 'actualizado_por_user_id'])]
class ConexionExterna extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'conexiones_externas';

    protected $hidden = ['credenciales'];

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoConexionExterna::class);
    }

    protected function casts(): array
    {
        return [
            'credenciales' => 'encrypted:array',
            'activa' => 'boolean',
            'version' => 'integer',
            'ultima_prueba_at' => 'immutable_datetime',
        ];
    }
}
