<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'operacion_retiro_material_id', 'user_id', 'payload_hash', 'antes', 'despues', 'motivo', 'fuera_plazo', 'version_anterior', 'version_resultante', 'ocurrido_at'])]
class RegularizacionDespachoMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'regularizaciones_despachos_materiales';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array', 'fuera_plazo' => 'boolean', 'ocurrido_at' => 'datetime'];
    }
}
