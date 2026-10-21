<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAsignacion extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_booking_asignaciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'acuerdo' => 'array', 'cantidad' => 'decimal:6'];
    }
}
