<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'unidad_dosis', 'activo', 'version', 'actualizado_por_user_id'])]
class ProductoHidrocooler extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'productos_hidrocooler';

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoProductoHidrocooler::class);
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer'];
    }
}
