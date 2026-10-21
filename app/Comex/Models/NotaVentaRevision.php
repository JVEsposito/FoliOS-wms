<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

class NotaVentaRevision extends Model
{
    protected $connection = 'comex';

    protected $table = 'cx_nota_revisiones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['datos' => 'array', 'numero' => 'integer', 'preparado_por_user_id' => 'integer', 'enviado_at' => 'immutable_datetime', 'decidido_at' => 'immutable_datetime'];
    }
}
