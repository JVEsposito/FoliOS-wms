<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorFinanciero extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_proveedores';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'pagos_bloqueados' => 'boolean', 'version' => 'integer'];
    }
}
