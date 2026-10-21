<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class ParteComercial extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_partes_comerciales';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['roles' => 'array', 'cliente_operacion' => 'array', 'activo' => 'boolean', 'version' => 'integer'];
    }
}
