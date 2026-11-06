<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'identificador', 'sector', 'funcion', 'jornada_base_minutos', 'activo', 'version'])]
class PersonaFrigorifico extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'frigorifico_personas';

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'version' => 'integer', 'jornada_base_minutos' => 'integer'];
    }
}
