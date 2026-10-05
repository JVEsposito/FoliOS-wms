<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspeccionEnvases extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'inspecciones_envases_recepcion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['formato' => 'array', 'inspeccionada_at' => 'immutable_datetime', 'coincide_especie_variedad' => 'boolean', 'coincide_cantidad_bins' => 'boolean', 'bins_bien_etiquetados' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItemInspeccionEnvase::class, 'inspeccion_envases_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoInspeccionEnvase::class, 'inspeccion_envases_id');
    }
}
