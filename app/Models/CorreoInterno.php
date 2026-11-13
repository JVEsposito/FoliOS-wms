<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['remitente_user_id', 'operacion_id', 'asunto', 'texto', 'respuesta_a_id'])]
class CorreoInterno extends Model
{
    protected $table = 'com_correos';

    protected function casts(): array
    {
        return ['remitente_user_id' => 'integer', 'respuesta_a_id' => 'integer'];
    }

    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remitente_user_id');
    }

    public function destinatarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'com_destinatarios', 'correo_id', 'user_id')->withPivot(['leido_at', 'archivado_at']);
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaComunicacion::class, 'correo_id')->orderBy('id');
    }
}
