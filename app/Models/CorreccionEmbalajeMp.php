<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operacion_id', 'payload_hash', 'entrega_fruta_proceso_id', 'version', 'anterior', 'nuevo', 'motivo', 'user_id'])]
class CorreccionEmbalajeMp extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'correcciones_embalaje_mp';

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(EntregaFrutaProceso::class, 'entrega_fruta_proceso_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'anterior' => 'array', 'nuevo' => 'array'];
    }
}
