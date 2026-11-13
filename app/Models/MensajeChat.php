<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversacion_id', 'remitente_user_id', 'operacion_id', 'texto', 'sticker_id'])]
class MensajeChat extends Model
{
    protected $table = 'com_mensajes';

    protected function casts(): array
    {
        return ['conversacion_id' => 'integer', 'remitente_user_id' => 'integer', 'sticker_id' => 'integer'];
    }

    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remitente_user_id');
    }

    public function sticker(): BelongsTo
    {
        return $this->belongsTo(StickerChat::class, 'sticker_id');
    }
}
