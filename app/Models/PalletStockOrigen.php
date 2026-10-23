<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Un pallet del stock de origen, tal como venía en la última lectura que lo incluyó. */
#[Fillable([
    'ambito', 'bodega', 'numero_pallet', 'tipo_bulto', 'tipo_mezclado', 'temporada_origen', 'temporada_origen_nombre', 'empresa',
    'especie', 'especie_codigo', 'variedad', 'variedad_codigo', 'calibre', 'categoria', 'envase_codigo', 'envase', 'envase_kilos',
    'marca', 'origen', 'mezclados', 'disponibilidad_origen',
    'cajas', 'kilos', 'fecha_embalaje', 'recibido_origen_at', 'composicion', 'huella', 'presente',
    'primera_lectura_at', 'ultima_lectura_at', 'modificado_origen_at', 'ausente_desde', 'lectura_stock_origen_id',
])]
class PalletStockOrigen extends Model
{
    use HasUuids;

    protected $table = 'pallets_stock_origen';

    protected function casts(): array
    {
        return [
            'tipo_mezclado' => 'boolean',
            'mezclados' => 'array',
            'cajas' => 'integer',
            'kilos' => 'decimal:2',
            'envase_kilos' => 'decimal:3',
            'fecha_embalaje' => 'date',
            'recibido_origen_at' => 'datetime',
            'composicion' => 'array',
            'presente' => 'boolean',
            'primera_lectura_at' => 'datetime',
            'ultima_lectura_at' => 'datetime',
            'modificado_origen_at' => 'datetime',
            'ausente_desde' => 'datetime',
        ];
    }
}
