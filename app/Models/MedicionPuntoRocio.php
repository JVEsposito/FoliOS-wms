<?php

namespace App\Models;

use App\Enums\EstadoMedicionPuntoRocio;
use App\Enums\EtapaMedicionPuntoRocio;
use App\Enums\GotasVisiblesPuntoRocio;
use App\Enums\TipoPuntoControlRocio;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Chequeo de condensación: aire, punto de rocío y margen frente a la superficie de la fruta. */
#[Fillable([
    'operacion_id',
    'payload_hash',
    'temporada_id',
    'etapa',
    'punto_tipo',
    'sala_control_rocio_id',
    'tunel_prefrio_id',
    'camara_id',
    'punto_nombre',
    'referencia',
    'temperatura_ambiente_c',
    'humedad_relativa',
    'temperatura_superficie_c',
    'punto_rocio_c',
    'margen_c',
    'vapor_g_m3',
    'margen_minimo_c',
    'estado',
    'gotas_visibles',
    'requiere_accion',
    'observacion',
    'medido_at',
    'fecha_operacional',
    'registrado_por_user_id',
    'dispositivo_id',
    'accion_correctiva',
    'accion_registrada_por_user_id',
    'accion_registrada_at',
    'anulada_at',
    'anulada_por_user_id',
    'motivo_anulacion',
])]
class MedicionPuntoRocio extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'mediciones_punto_rocio';

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_user_id');
    }

    public function accionRegistradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accion_registrada_por_user_id');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por_user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function alertaPendiente(): bool
    {
        return $this->requiere_accion && $this->accion_registrada_at === null && $this->anulada_at === null;
    }

    protected function casts(): array
    {
        return [
            'etapa' => EtapaMedicionPuntoRocio::class,
            'punto_tipo' => TipoPuntoControlRocio::class,
            'estado' => EstadoMedicionPuntoRocio::class,
            'gotas_visibles' => GotasVisiblesPuntoRocio::class,
            'temperatura_ambiente_c' => 'float',
            'humedad_relativa' => 'float',
            'temperatura_superficie_c' => 'float',
            'punto_rocio_c' => 'float',
            'margen_c' => 'float',
            'vapor_g_m3' => 'float',
            'margen_minimo_c' => 'float',
            'requiere_accion' => 'boolean',
            'medido_at' => 'immutable_datetime',
            'fecha_operacional' => 'immutable_date',
            'accion_registrada_at' => 'immutable_datetime',
            'anulada_at' => 'immutable_datetime',
        ];
    }
}
