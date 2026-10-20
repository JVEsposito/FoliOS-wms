<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una definición inmutable: un cambio de proceso o reglas se registra como otro concepto. */
class ConceptoServicio extends Model
{
    public const ADICIONAL = 'adicional';

    public const REEMPLAZA_BASE = 'reemplaza_base';

    protected $connection = 'finanzas';

    protected $table = 'fin_servicios';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date', 'vigente_hasta' => 'date', 'dia_desde' => 'integer', 'dia_hasta' => 'integer'];
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoServicio::class, 'servicio', 'clave');
    }
}
