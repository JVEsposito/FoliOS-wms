<?php

namespace App\Models;

use App\Enums\TipoTemporada;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'codigo',
    'nombre',
    'fecha_inicio',
    'fecha_fin',
    'activa',
    'prefijo_documental',
    'version_catalogo',
    'intervalo_embarques_minutos',
])]
class Temporada extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    /** @var array<string, mixed> */
    protected $attributes = [
        'tipo' => 'productiva',
    ];

    /**
     * Temporadas que se ofrecen en selectores y reportes operacionales. Las de
     * prueba siguen consultables en Accesos y en Materiales.
     */
    public function scopeProductivas(Builder $consulta): Builder
    {
        return $consulta->where('tipo', TipoTemporada::Productiva->value);
    }

    public function esPrueba(): bool
    {
        return $this->tipo === TipoTemporada::Prueba;
    }

    public function incluyeFecha(string $fecha): bool
    {
        return ($this->fecha_inicio === null || $fecha >= $this->fecha_inicio->toDateString())
            && ($this->fecha_fin === null || $fecha <= $this->fecha_fin->toDateString());
    }

    public function clasificaciones(): HasMany
    {
        return $this->hasMany(ClasificacionTemporada::class);
    }

    public function ultimaClasificacion(): HasOne
    {
        return $this->hasOne(ClasificacionTemporada::class)->ofMany(['clasificado_at' => 'max', 'id' => 'max']);
    }

    public function configuracionMaterial(): HasOne
    {
        return $this->hasOne(TemporadaMaterial::class);
    }

    public function recepcionesRomana(): HasMany
    {
        return $this->hasMany(RecepcionRomana::class);
    }

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function planesOperacionales(): HasMany
    {
        return $this->hasMany(PlanOperacional::class);
    }

    public function ciclosArbitrajeManiobras(): HasMany
    {
        return $this->hasMany(CicloArbitrajeManiobras::class);
    }

    public function embarques(): HasMany
    {
        return $this->hasMany(Embarque::class);
    }

    public function migracionesRecibidas(): HasMany
    {
        return $this->hasMany(MigracionTemporada::class, 'temporada_destino_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(ClienteValidacion::class);
    }

    public function especies(): HasMany
    {
        return $this->hasMany(EspecieValidacion::class);
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(CategoriaValidacion::class);
    }

    public function csg(): HasMany
    {
        return $this->hasMany(CsgValidacion::class);
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(ArticuloValidacion::class);
    }

    public function origenes(): HasMany
    {
        return $this->hasMany(OrigenValidacion::class);
    }

    public function combinaciones(): HasMany
    {
        return $this->hasMany(CombinacionValidacion::class);
    }

    public function importaciones(): HasMany
    {
        return $this->hasMany(ImportacionValidacion::class);
    }

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'activa' => 'boolean',
            'tipo' => TipoTemporada::class,
            'version_catalogo' => 'integer',
            'intervalo_embarques_minutos' => 'integer',
        ];
    }
}
