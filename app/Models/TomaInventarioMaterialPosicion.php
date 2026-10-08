<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TomaInventarioMaterialPosicion extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'tomas_inventario_materiales_posiciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'lecturas' => 'array', 'contada_at' => 'datetime'];
    }

    public function toma(): BelongsTo
    {
        return $this->belongsTo(TomaInventarioMaterial::class, 'toma_id');
    }

    public function posicion(): BelongsTo
    {
        return $this->belongsTo(Posicion::class);
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(TomaInventarioMaterialResultado::class, 'toma_posicion_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->toma->temporada_id;
    }
}
