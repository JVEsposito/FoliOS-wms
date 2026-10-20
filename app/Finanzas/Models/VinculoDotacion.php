<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class VinculoDotacion extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_dotacion';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
