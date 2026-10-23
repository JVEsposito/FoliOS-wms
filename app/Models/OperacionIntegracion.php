<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Metadatos globales. Entrada y resultado temporal cifrados; ningún secreto en el job. */
class OperacionIntegracion extends Model
{
    use HasUuids;

    protected $table = 'operaciones_integracion';

    protected $guarded = ['id'];

    protected $hidden = ['entrada', 'resultado', 'clave_activa'];

    protected function casts(): array
    {
        return ['entrada' => 'encrypted:array', 'resultado' => 'encrypted:array',
            'version_conexion' => 'integer', 'user_id' => 'integer',
            'resultado_hasta' => 'immutable_datetime', 'iniciada_at' => 'immutable_datetime', 'terminada_at' => 'immutable_datetime'];
    }
}
