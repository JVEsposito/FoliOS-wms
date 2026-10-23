<?php

namespace App\Models;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\SentidoNumeracionBandas;
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
    'tipo',
    'contenido',
    'estado',
    'version_plano',
    'cantidad_bandas',
    'posiciones_por_banda',
    'cantidad_niveles',
    'sentido_numeracion_bandas',
    'creado_por_user_id',
    'actualizado_por_user_id',
])]
class Camara extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    public function posiciones(): HasMany
    {
        return $this->hasMany(Posicion::class);
    }

    public function bandasOperacionales(): HasMany
    {
        return $this->hasMany(BandaOperacional::class)->orderBy('numero');
    }

    public function ubicacionesActuales(): HasMany
    {
        return $this->hasMany(UbicacionActual::class);
    }

    public function ubicacionesSinPosicion(): HasMany
    {
        return $this->ubicacionesActuales()->whereNull('posicion_id');
    }

    public function saldosMateriales(): HasMany
    {
        return $this->hasMany(SaldoMaterialAlmacen::class);
    }

    public function sesionesEstiba(): HasMany
    {
        return $this->hasMany(SesionEstiba::class);
    }

    public function registrosControlAmbiental(): HasMany
    {
        return $this->hasMany(RegistroControlAmbiental::class);
    }

    public function bloqueo(): HasOne
    {
        return $this->hasOne(BloqueoCamara::class);
    }

    public function movimientosOrigen(): HasMany
    {
        return $this->hasMany(Movimiento::class, 'camara_origen_id');
    }

    public function movimientosDestino(): HasMany
    {
        return $this->hasMany(Movimiento::class, 'camara_destino_id');
    }

    public function tareasMovimientoOrigen(): HasMany
    {
        return $this->hasMany(TareaMovimiento::class, 'camara_origen_id');
    }

    public function tareasMovimientoDestino(): HasMany
    {
        return $this->hasMany(TareaMovimiento::class, 'camara_destino_id');
    }

    public function asignacionesLotesMateriaPrima(): HasMany
    {
        return $this->hasMany(AsignacionCamaraLoteMateriaPrima::class);
    }

    public function sublotesRetornoPacking(): HasMany
    {
        return $this->hasMany(SubloteRetornoPacking::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoCamara::class,
            'contenido' => ContenidoCamara::class,
            'version_plano' => 'integer',
            'revision_reservas' => 'integer',
            'cantidad_bandas' => 'integer',
            'posiciones_por_banda' => 'integer',
            'cantidad_niveles' => 'integer',
            'sentido_numeracion_bandas' => SentidoNumeracionBandas::class,
        ];
    }
}
