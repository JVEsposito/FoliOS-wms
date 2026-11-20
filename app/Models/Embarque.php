<?php

namespace App\Models;

use App\Enums\EstadoCarga;
use App\Enums\EstadoEmbarque;
use App\Enums\ModalidadEmbarque;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'temporada_id', 'cliente_id', 'carga_id', 'codigo', 'numero_correlativo',
    'fecha_programada', 'hora_programada', 'intervalo_minutos', 'modalidad', 'estado',
    'referencia_correo', 'nave_vuelo', 'transportista', 'puerto_embarque',
    'puerto_embarque_id',
    'contenedor', 'sello', 'patente_camion', 'patente_trasera', 'documentos',
    'observacion', 'version', 'creado_por_user_id', 'actualizado_por_user_id',
    'sobrecupo_autorizado_por_user_id', 'sobrecupo_motivo', 'sobrecupo_autorizado_at',
    'confirmado_por_user_id', 'confirmado_at', 'cancelado_por_user_id',
    'cancelacion_motivo', 'cancelado_at',
])]
class Embarque extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    /** Solo puede editarse la programación antes de comenzar la operación física. */
    public function editableEnCalendario(): bool
    {
        if ($this->estado === EstadoEmbarque::Cancelado) {
            return false;
        }

        if ($this->carga_id === null) {
            return $this->estado === EstadoEmbarque::Tentativo;
        }

        $carga = $this->carga;

        return $carga !== null && in_array($carga->estado, [
            EstadoCarga::Borrador,
            EstadoCarga::Pendiente,
        ], true);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    public function puertoEmbarque(): BelongsTo
    {
        return $this->belongsTo(Puerto::class, 'puerto_embarque_id');
    }

    public function instructivos(): HasMany
    {
        return $this->hasMany(InstructivoEmbarque::class)->orderBy('orden');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoEmbarque::class)->orderBy('created_at');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    public function sobrecupoAutorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sobrecupo_autorizado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_programada' => 'date',
            'modalidad' => ModalidadEmbarque::class,
            'estado' => EstadoEmbarque::class,
            'intervalo_minutos' => 'integer',
            'version' => 'integer',
            'sobrecupo_autorizado_at' => 'datetime',
            'confirmado_at' => 'datetime',
            'cancelado_at' => 'datetime',
        ];
    }
}
