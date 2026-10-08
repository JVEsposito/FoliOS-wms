<?php

namespace App\Models;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\TipoEventoBloqueoMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operacion_id',
    'folio_id',
    'tipo',
    'estado_anterior',
    'estado_resultante',
    'motivo',
    'user_id',
    'ocurrido_at',
    'metadatos',
])]
class EventoBloqueoMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eventos_bloqueos_materiales';

    public function folioMaterial(): BelongsTo
    {
        return $this->belongsTo(FolioMaterial::class, 'folio_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoBloqueoMaterial::class,
            'estado_anterior' => EstadoOperacionalFolio::class,
            'estado_resultante' => EstadoOperacionalFolio::class,
            'ocurrido_at' => 'datetime',
            'metadatos' => 'array',
        ];
    }
}
