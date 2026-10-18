<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hasta dónde se leyó cada fuente. La lectura siguiente parte un margen antes de esta
 * marca (config finanzas.captura.margen_minutos).
 *
 * @property string $fuente
 * @property ?Carbon $marca_at
 * @property ?int $lectura_id
 * @property Carbon $actualizado_at
 */
class Cursor extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_cursores';

    protected $primaryKey = 'fuente';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'marca_at' => 'datetime',
            'actualizado_at' => 'datetime',
        ];
    }
}
