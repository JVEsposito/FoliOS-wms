<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['producto_hidrocooler_id', 'user_id', 'antes', 'despues'])]
class EventoProductoHidrocooler extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eventos_producto_hidrocooler';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
