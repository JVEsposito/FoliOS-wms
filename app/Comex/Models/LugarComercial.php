<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class LugarComercial extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_lugares_comerciales';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer'];
    }
}
