<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsientoCompra extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_asientos_compra';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorFinanciero::class, 'proveedor_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaAsientoCompra::class, 'asiento_id');
    }
}
