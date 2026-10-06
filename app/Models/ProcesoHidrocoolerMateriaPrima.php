<?php

namespace App\Models;

use App\Enums\EstadoHidrocoolerMateriaPrima;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'codigo',
    'temperatura_ambiente_c', 'humedad_relativa_pct', 'pozo_accutab_mv', 'recarga_pastilla',
    'correccion_cloro_ppm', 'aplicacion_producto', 'producto_hidrocooler_id', 'producto_nombre_snapshot',
    'producto_dosis', 'producto_unidad_dosis', 'formato_registro_snapshot',
    'cloro_min_ppm_snapshot', 'cloro_max_ppm_snapshot',
    'lote_materia_prima_id',
    'operacion_inicio_id',
    'payload_inicio_hash',
    'operacion_termino_id',
    'payload_termino_hash',
    'estado',
    'equipo',
    'turno',
    'cantidad_bombas_funcionando',
    'equipo_activo_clave',
    'operador_snapshot',
    'cantidad_envases_snapshot',
    'kilos_netos_snapshot',
    'inicio_at',
    'termino_at',
    'duracion_minutos',
    'temperatura_inicial_c',
    'temperatura_objetivo_c',
    'temperatura_agua_inicial_c',
    'cloro_libre_ppm',
    'ph_agua',
    'condicion_visual_agua',
    'dosificador_operativo',
    'manejo_agua',
    'control_inicial_conforme',
    'cloro_libre_final_ppm',
    'ph_agua_final',
    'condicion_visual_agua_final',
    'dosificador_operativo_final',
    'control_final_conforme',
    'motivo_retencion',
    'operacion_liberacion_id',
    'payload_liberacion_hash',
    'temperatura_verificacion_c',
    'cloro_libre_verificacion_ppm',
    'ph_agua_verificacion',
    'evaluacion_producto',
    'verificacion_liberacion',
    'liberado_por_user_id',
    'liberado_at',
    'temperatura_c',
    'temperatura_agua_final_c',
    'destino_salida',
    'observacion_inicio',
    'observacion',
    'accion_correctiva',
    'iniciado_por_user_id',
    'completado_por_user_id',
])]
class ProcesoHidrocoolerMateriaPrima extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'procesos_hidrocooler_materia_prima';

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteMateriaPrima::class, 'lote_materia_prima_id');
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por_user_id');
    }

    public function completadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completado_por_user_id');
    }

    public function liberadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'temperatura_ambiente_c' => 'decimal:2', 'humedad_relativa_pct' => 'decimal:2',
            'pozo_accutab_mv' => 'decimal:2', 'recarga_pastilla' => 'boolean',
            'correccion_cloro_ppm' => 'decimal:2', 'aplicacion_producto' => 'boolean',
            'producto_dosis' => 'decimal:4', 'formato_registro_snapshot' => 'array',
            'cloro_min_ppm_snapshot' => 'decimal:2', 'cloro_max_ppm_snapshot' => 'decimal:2',
            'estado' => EstadoHidrocoolerMateriaPrima::class,
            'inicio_at' => 'datetime',
            'termino_at' => 'datetime',
            'duracion_minutos' => 'integer',
            'cantidad_envases_snapshot' => 'integer',
            'cantidad_bombas_funcionando' => 'integer',
            'kilos_netos_snapshot' => 'decimal:3',
            'temperatura_inicial_c' => 'decimal:2',
            'temperatura_objetivo_c' => 'decimal:2',
            'temperatura_agua_inicial_c' => 'decimal:2',
            'cloro_libre_ppm' => 'decimal:2',
            'ph_agua' => 'decimal:2',
            'dosificador_operativo' => 'boolean',
            'control_inicial_conforme' => 'boolean',
            'cloro_libre_final_ppm' => 'decimal:2',
            'ph_agua_final' => 'decimal:2',
            'dosificador_operativo_final' => 'boolean',
            'control_final_conforme' => 'boolean',
            'temperatura_verificacion_c' => 'decimal:2',
            'cloro_libre_verificacion_ppm' => 'decimal:2',
            'ph_agua_verificacion' => 'decimal:2',
            'liberado_at' => 'datetime',
            'temperatura_c' => 'decimal:2',
            'temperatura_agua_final_c' => 'decimal:2',
        ];
    }
}
