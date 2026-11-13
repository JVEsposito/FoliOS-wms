<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'operacion_id', 'nombre', 'ruta', 'mime', 'sha256', 'activo'])]
#[Hidden(['ruta', 'sha256', 'operacion_id'])]
class StickerChat extends Model
{
    protected $table = 'com_stickers';

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'user_id' => 'integer'];
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
