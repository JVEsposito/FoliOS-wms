<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'dispositivo_id', 'temporada_id', 'personal_access_token_id', 'estado',
    'ultima_actividad_at', 'desconectada_at', 'disponible_desde', 'aviso_disponible_id'])]
class PresenciaCamarero extends Model
{
    use HasUuids;

    protected $table = 'presencias_camareros';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    protected function casts(): array
    {
        return ['ultima_actividad_at' => 'immutable_datetime', 'desconectada_at' => 'immutable_datetime', 'disponible_desde' => 'immutable_datetime'];
    }
}
