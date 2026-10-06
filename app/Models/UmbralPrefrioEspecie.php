<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['especie', 'temperatura_maxima_c', 'version'])]
class UmbralPrefrioEspecie extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'umbrales_prefrio_especies';

    protected function casts(): array
    {
        return ['temperatura_maxima_c' => 'decimal:2', 'version' => 'integer'];
    }
}
