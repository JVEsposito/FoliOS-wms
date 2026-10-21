<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class EventoCompra extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_eventos_compra';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }
}
