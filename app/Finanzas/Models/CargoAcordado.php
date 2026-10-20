<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CargoAcordado extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $connection = 'finanzas';

    protected $table = 'fin_cargos';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'cantidad' => 'decimal:6', 'contexto' => 'array', 'aprobado_at' => 'datetime', 'anulado_at' => 'datetime'];
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoCargo::class, 'cargo_id');
    }
}
