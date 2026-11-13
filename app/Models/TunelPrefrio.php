<?php

namespace App\Models;

use App\Enums\EstadoAdministrativoTunelPrefrio;
use App\Enums\EstadoTecnicoTunelPrefrio;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'codigo',
    'nombre',
    'capacidad_posiciones',
    'setpoint_habitual',
    'estado_administrativo',
    'estado_tecnico',
    'codigo_externo',
    'observacion',
    'version_configuracion',
    'creado_por_user_id',
])]
class TunelPrefrio extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'tuneles_prefrio';

    public function posiciones(): HasMany
    {
        return $this->hasMany(PosicionTunelPrefrio::class);
    }

    public function procesos(): HasMany
    {
        return $this->hasMany(ProcesoPrefrio::class);
    }

    public function procesoActivo(): HasOne
    {
        return $this->hasOne(ProcesoPrefrio::class)
            ->ocupaTunel()
            ->latestOfMany();
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'capacidad_posiciones' => 'integer',
            'setpoint_habitual' => 'decimal:2',
            'estado_administrativo' => EstadoAdministrativoTunelPrefrio::class,
            'estado_tecnico' => EstadoTecnicoTunelPrefrio::class,
            'version_configuracion' => 'integer',
        ];
    }
}
