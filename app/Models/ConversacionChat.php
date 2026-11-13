<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tipo', 'clave', 'ultimo_mensaje_id'])]
class ConversacionChat extends Model
{
    protected $table = 'com_conversaciones';

    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'com_participantes', 'conversacion_id', 'user_id')->withPivot('ultimo_leido_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeChat::class, 'conversacion_id');
    }

    public function ultimoMensaje(): BelongsTo
    {
        return $this->belongsTo(MensajeChat::class, 'ultimo_mensaje_id');
    }
}
