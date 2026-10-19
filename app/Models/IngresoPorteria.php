<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bitácora física por fecha; independiente de las temporadas e inventarios. */
class IngresoPorteria extends Model
{
    use ImpideEliminacionFisica;

    protected $table = 'ingresos_porteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['guias' => 'array', 'cliente_es_otro' => 'boolean', 'cantidad' => 'integer', 'version' => 'integer', 'ingreso_at' => 'datetime', 'revisado_at' => 'datetime', 'anulado_at' => 'datetime'];
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoIngresoPorteria::class);
    }

    public function codigo(): string
    {
        return 'PORT-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
