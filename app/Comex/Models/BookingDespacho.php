<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class BookingDespacho extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_booking_despachos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'evidencia' => 'array', 'captured_at' => 'immutable_datetime'];
    }
}
