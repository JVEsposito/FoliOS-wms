<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class BookingContenedor extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_booking_contenedores';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
