<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tipo', 'clave', 'ultimo_mensaje_id', 'nombre', 'creador_user_id', 'revision_moderacion'])]
class ConversacionChat extends Model
{
    protected $table = 'com_conversaciones';

    protected function casts(): array
    {
        return ['creador_user_id' => 'integer', 'revision_moderacion' => 'integer'];
    }

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
