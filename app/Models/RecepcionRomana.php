<?php

namespace App\Models;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoCamionRomana;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoRecepcionRomana;
use App\Enums\TipoServicioRomana;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'operacion_id',
    'payload_hash',
    'numero_recepcion',
    'temporada_id',
    'temporada_codigo_snapshot',
    'temporada_nombre_snapshot',
    'cliente_id',
    'cliente_codigo_snapshot',
    'cliente_nombre_snapshot',
    'especie_validacion_id',
    'tipo_recepcion',
    'concepto_envases',
    'tipo_servicio',
    'cantidad_envases_declarados',
    'tipo_envase_declarado',
    'numero_guia_despacho',
    'patente_camion',
    'tipo_camion',
    'patente_carro',
    'rut_conductor',
    'nombre_conductor',
    'peso_bruto',
    'peso_tara',
    'peso_neto',
    'salida_sin_envases',
    'modo_salida_envases',
    'numero_guia_salida',
    'peso_tara_envases',
    'tipo_envase_calculo_neto',
    'tipo_envase_pesaje',
    'tara_unitaria_envase',
    'cantidad_envases_pesados',
    'cantidad_envase_calculo_neto',
    'peso_neto_por_envase',
    'estado',
    'estado_validacion_mp',
    'ingreso_at',
    'ingreso_confirmado_at',
    'salida_at',
    'validacion_tomada_at',
    'validado_at',
    'version',
    'creado_por_user_id',
    'ingreso_confirmado_por_user_id',
    'cerrado_por_user_id',
    'validacion_tomada_por_user_id',
    'observacion',
    'observacion_cierre',
    'formato_registro_codigo',
    'formato_registro_version',
    'formato_registro_fecha_vigencia',
    'formato_registro_localidad',
])]
class RecepcionRomana extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'recepciones_romana';

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoRecepcionRomana::class, 'recepcion_romana_id');
    }

    public function detallesEnvases(): HasMany
    {
        return $this->hasMany(DetalleEnvaseRecepcionRomana::class, 'recepcion_romana_id');
    }

    public function salidasEnvases(): HasMany
    {
        return $this->hasMany(SalidaEnvaseRecepcionRomana::class, 'recepcion_romana_id');
    }

    public function pesajesEnvases(): HasMany
    {
        return $this->hasMany(PesajeEnvaseRecepcionRomana::class, 'recepcion_romana_id');
    }

    public function movimientosEnvases(): HasMany
    {
        return $this->hasMany(MovimientoEnvase::class, 'recepcion_romana_id');
    }

    public function validacionesMp(): HasMany
    {
        return $this->hasMany(ValidacionMp::class, 'recepcion_romana_id');
    }

    public function lotesMateriaPrima(): HasMany
    {
        return $this->hasMany(LoteMateriaPrima::class, 'recepcion_romana_id');
    }

    public function validacionTomadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validacion_tomada_por_user_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function ingresoConfirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ingreso_confirmado_por_user_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_servicio' => TipoServicioRomana::class,
            'tipo_camion' => TipoCamionRomana::class,
            'tipo_recepcion' => TipoRecepcionRomana::class,
            'concepto_envases' => ConceptoEnvasesRomana::class,
            'tipo_envase_declarado' => TipoEnvaseRomana::class,
            'estado' => EstadoRecepcionRomana::class,
            'estado_validacion_mp' => EstadoValidacionMp::class,
            'cantidad_envases_declarados' => 'integer',
            'peso_bruto' => 'decimal:3',
            'peso_tara' => 'decimal:3',
            'peso_neto' => 'decimal:3',
            'salida_sin_envases' => 'boolean',
            'peso_tara_envases' => 'decimal:3',
            'tipo_envase_pesaje' => TipoEnvaseRomana::class,
            'tara_unitaria_envase' => 'decimal:3',
            'cantidad_envases_pesados' => 'integer',
            'cantidad_envase_calculo_neto' => 'integer',
            'peso_neto_por_envase' => 'decimal:3',
            'ingreso_at' => 'datetime',
            'ingreso_confirmado_at' => 'datetime',
            'salida_at' => 'datetime',
            'validacion_tomada_at' => 'datetime',
            'validado_at' => 'datetime',
            'formato_registro_fecha_vigencia' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
