<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['productor_csg_id', 'temporada_id', 'codigo', 'predio', 'codigo_externo', 'activo'])]
class CsgValidacion extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'csg_validacion';

    public function productor(): BelongsTo
    {
        return $this->belongsTo(ProductorCsg::class, 'productor_csg_id');
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function predioRegistrado(): ?string
    {
        $predio = trim($this->predio ?? '');

        return $predio !== '' && mb_strtolower($predio) !== 'sin predio informado' ? $predio : null;
    }

    public function variedades(): BelongsToMany
    {
        return $this->belongsToMany(
            VariedadValidacion::class,
            'csg_variedades_validacion',
            'csg_validacion_id',
            'variedad_validacion_id',
        )->withTimestamps();
    }

    public function scopeDisponibleParaCliente(Builder $consulta, string $clienteId): Builder
    {
        return $consulta->where(function (Builder $alcance) use ($clienteId): void {
            $alcance->whereNull('productor_csg_id')
                ->orWhereHas('productor.clientes', function (Builder $clientes) use ($clienteId): void {
                    $clientes->where('clientes.id', $clienteId)
                        ->where('clientes_productores_csg.activo', true);
                });
        });
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
