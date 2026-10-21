<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class OperacionComercial extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_operaciones_comerciales';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['resultado' => 'array'];
    }
}
