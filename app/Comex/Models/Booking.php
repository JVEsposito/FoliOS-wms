<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_bookings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cliente' => 'array', 'datos' => 'array', 'version' => 'integer'];
    }
}
