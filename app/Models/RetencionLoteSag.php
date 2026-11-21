<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['temporada_id', 'cliente_id', 'numero_lote', 'lote_materia_prima_id', 'estado', 'fecha_deteccion',
    'observacion', 'referencia_informe', 'todos_destinos', 'destinos', 'resolucion', 'definitivo',
    'instruccion_segregacion', 'version', 'creado_por_user_id', 'actualizado_por_user_id'])]
class RetencionLoteSag extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    public const LIBERADO_CON_RESTRICCIONES = 'liberado_con_restricciones';

    public const ESTADOS = ['con_observaciones', 'retenido', self::LIBERADO_CON_RESTRICCIONES, 'liberado'];

    protected $table = 'retenciones_lote_sag';

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function getEstadoAttribute(string $estado): string
    {
        return $estado === 'retenido' && ! $this->todos_destinos && filled($this->destinos)
            ? self::LIBERADO_CON_RESTRICCIONES
            : $estado;
    }

    public function scopeConEstado(Builder $consulta, string $estado): Builder
    {
        if ($estado === self::LIBERADO_CON_RESTRICCIONES) {
            return $consulta->where(fn (Builder $q) => $q->where('estado', $estado)
                ->orWhere(fn (Builder $anterior) => $anterior->where('estado', 'retenido')
                    ->where('todos_destinos', false)->whereJsonLength('destinos', '>', 0)));
        }

        return $consulta->where('estado', $estado)
            ->when($estado === 'retenido', fn (Builder $q) => $q->where(fn (Builder $general) => $general
                ->where('todos_destinos', true)->orWhereJsonLength('destinos', 0)));
    }

    protected function casts(): array
    {
        return ['destinos' => 'array', 'todos_destinos' => 'boolean', 'definitivo' => 'boolean',
            'version' => 'integer', 'fecha_deteccion' => 'date:Y-m-d'];
    }
}
