<?php

namespace App\Models;

use App\Enums\EstadoReingresoCamara;
use App\Enums\OrigenReingresoCamara;
use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'temporada_id',
    'folio_id',
    'folio_pendiente_id',
    'carga_id',
    'carga_folio_id',
    'origen',
    'estado',
    'estado_carga_previo',
    'anden_id',
    'motivo',
    'registrado_por_user_id',
    'registrado_at',
    'movimiento_ubicacion_id',
    'camara_ubicacion_id',
    'ubicado_por_user_id',
    'ubicado_at',
])]
class ReingresoFolioCamara extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'reingresos_folio_camara';

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(CargaFolio::class, 'carga_folio_id');
    }

    public function anden(): BelongsTo
    {
        return $this->belongsTo(Anden::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_user_id');
    }

    public function ubicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ubicado_por_user_id');
    }

    public function camaraUbicacion(): BelongsTo
    {
        return $this->belongsTo(Camara::class, 'camara_ubicacion_id');
    }

    protected function casts(): array
    {
        return [
            'origen' => OrigenReingresoCamara::class,
            'estado' => EstadoReingresoCamara::class,
            'registrado_at' => 'datetime',
            'ubicado_at' => 'datetime',
        ];
    }
}
