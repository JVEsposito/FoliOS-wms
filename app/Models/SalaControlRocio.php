<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Sala o zona sin registro propio donde se controla la condensación (sellado, paletizado…). */
#[Fillable(['nombre', 'activa', 'version', 'creada_por_user_id'])]
class SalaControlRocio extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'salas_control_rocio';

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'version' => 'integer'];
    }
}
