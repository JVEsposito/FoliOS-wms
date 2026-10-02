<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ItemInspeccionEnvase extends Model
{
    use HasUuids;

    protected $table = 'items_inspeccion_envases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['limpieza' => 'boolean', 'cantidad' => 'integer'];
    }
}
