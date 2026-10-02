<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Services\Romana\ServicioRepartoEnvases;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

#[Fillable(['temporada_id', 'nombre', 'codigo_externo', 'activo'])]
class EspecieValidacion extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'especies_validacion';

    protected static function booted(): void
    {
        static::created(function (self $especie): void {
            if (Schema::hasTable('pesos_referencia_envases')) {
                app(ServicioRepartoEnvases::class)->precargarCereza($especie);
            }
        });
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function variedades(): HasMany
    {
        return $this->hasMany(VariedadValidacion::class);
    }

    public function calibres(): HasMany
    {
        return $this->hasMany(CalibreValidacion::class);
    }

    public function envases(): HasMany
    {
        return $this->hasMany(EnvaseValidacion::class);
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
