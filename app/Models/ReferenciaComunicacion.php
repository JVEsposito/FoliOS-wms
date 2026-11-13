<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Cita de un registro de FoliOS o mención de un usuario dentro de un mensaje o correo. */
#[Fillable(['mensaje_id', 'correo_id', 'tipo', 'referencia_id', 'etiqueta'])]
class ReferenciaComunicacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'com_referencias';

    protected function casts(): array
    {
        return ['mensaje_id' => 'integer', 'correo_id' => 'integer'];
    }
}
