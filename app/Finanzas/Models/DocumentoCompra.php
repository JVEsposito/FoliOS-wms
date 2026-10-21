<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoCompra extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_documentos_compra';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['proveedor_snapshot' => 'array', 'lineas' => 'array', 'guias' => 'array', 'fecha_emision' => 'date:Y-m-d', 'fecha_recepcion' => 'date:Y-m-d', 'vencimiento' => 'date:Y-m-d', 'fecha_contable' => 'date:Y-m-d', 'fecha_anulacion' => 'date:Y-m-d', 'registrado_at' => 'datetime', 'anulado_at' => 'datetime', 'version' => 'integer', 'revision' => 'integer', 'neto' => 'decimal:0', 'exento' => 'decimal:0', 'iva' => 'decimal:0', 'otros' => 'decimal:0', 'total' => 'decimal:0'];
    }
}
