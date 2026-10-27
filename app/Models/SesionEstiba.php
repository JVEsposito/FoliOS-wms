<?php

namespace App\Models;

use App\Enums\EstadoSesionEstiba;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'camara_id',
    'user_id',
    'dispositivo_id',
    'estado',
    'version_inicial',
    'version_final',
    'iniciada_at',
    'ultima_actividad_at',
    'cerrada_at',
    'cierre_forzado_por_user_id',
    'motivo_cierre',
    'tarea_movimiento_id',
    'clave_tarea_activa',
])]
class SesionEstiba extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'sesiones_estiba';

    public function camara(): BelongsTo
    {
        return $this->belongsTo(Camara::class);
    }

    public function tareaMovimiento(): BelongsTo
    {
        return $this->belongsTo(TareaMovimiento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function cierreForzadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cierre_forzado_por_user_id');
    }

    public function bloqueo(): HasOne
    {
        return $this->hasOne(BloqueoCamara::class);
    }

    public function movimientosOrigen(): HasMany
    {
        return $this->hasMany(Movimiento::class, 'sesion_origen_id');
    }

    public function movimientosDestino(): HasMany
    {
        return $this->hasMany(Movimiento::class, 'sesion_destino_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoSesionEstiba::class,
            'iniciada_at' => 'datetime',
            'ultima_actividad_at' => 'datetime',
            'cerrada_at' => 'datetime',
            'version_inicial' => 'integer',
            'version_final' => 'integer',
        ];
    }
}
