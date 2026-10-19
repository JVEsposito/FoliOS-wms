<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['codigo', 'nombre', 'proporcion', 'version', 'elementos', 'conexiones', 'actualizado_por_user_id'])]
class PlanoPlanta extends Model
{
    use HasUuids;

    protected $table = 'planos_planta';

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'proporcion' => 'float',
            'elementos' => 'array',
            'conexiones' => 'array',
        ];
    }
}
