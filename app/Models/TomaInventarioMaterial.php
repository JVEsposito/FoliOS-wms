<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TomaInventarioMaterial extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'tomas_inventario_materiales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['camara_ids' => 'array', 'foto' => 'array', 'operaciones' => 'array', 'version' => 'integer', 'abierta_at' => 'datetime', 'revisada_at' => 'datetime', 'aprobada_at' => 'datetime', 'anulada_at' => 'datetime'];
    }

    public function posiciones(): HasMany
    {
        return $this->hasMany(TomaInventarioMaterialPosicion::class, 'toma_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->temporada_id;
    }
}
