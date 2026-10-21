<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ResponsableContraparteSag extends Model
{
    use HasUuids;

    protected $table = 'responsables_contraparte_sag';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer'];
    }
}
