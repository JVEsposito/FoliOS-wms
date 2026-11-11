<?php

namespace App\Models;

use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pallet de la R-24: dónde quedó dentro del camión o contenedor y con qué temperatura. */
class PosicionControlDespacho extends Model implements PerteneceATemporada
{
    use HasUuids;

    protected $table = 'posiciones_control_despacho';

    protected $guarded = [];

    public function control(): BelongsTo
    {
        return $this->belongsTo(ControlDespacho::class, 'control_despacho_id');
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(CargaFolio::class, 'carga_folio_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_user_id');
    }

    public function temporadaOperacionalId(): ?string
    {
        return $this->control?->temporadaOperacionalId();
    }

    protected function casts(): array
    {
        return [
            'posicion' => 'integer',
            'cajas' => 'integer',
            'temperatura' => 'decimal:1',
            'registrado_at' => 'datetime',
        ];
    }
}
