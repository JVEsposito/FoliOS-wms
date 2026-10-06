<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tipo', 'registro_id', 'user_id', 'antes', 'despues'])]
class EventoCatalogoFrutaEmbalada extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'eventos_catalogo_fruta_embalada';

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
