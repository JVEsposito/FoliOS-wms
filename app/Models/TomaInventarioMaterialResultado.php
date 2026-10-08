<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TomaInventarioMaterialResultado extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'tomas_inventario_materiales_resultados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['vigente' => 'boolean', 'movimientos' => 'array', 'saldo_confirmado' => 'array', 'cantidad_inicial' => 'decimal:3', 'cantidad_esperada' => 'decimal:3', 'cantidad_contada' => 'decimal:3', 'diferencia' => 'decimal:3', 'diferencia_pct' => 'decimal:3'];
    }

    public function tarea(): BelongsTo
    {
        return $this->belongsTo(TomaInventarioMaterialPosicion::class, 'toma_posicion_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_material_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->tarea->temporadaOperacionalId();
    }
}
