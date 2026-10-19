<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lo que un hecho aportó a una línea del mes cerrado: su cantidad y, en el almacenaje, los días
 * cobrados. Identifica el hecho por fuente y origen, porque una corrección crea otra versión.
 *
 * @property int $id
 * @property int $cierre_id
 * @property string $cliente_id
 * @property int $tarifa_id
 * @property string $moneda
 * @property int $hecho_id
 * @property string $fuente
 * @property string $origen_id
 * @property int $version
 * @property ?string $referencia
 * @property Carbon $desde
 * @property Carbon $hasta
 * @property int $dias
 * @property string $cantidad
 */
class AporteCierre extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_cierres_aportes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tarifa_id' => 'integer',
            'hecho_id' => 'integer',
            'version' => 'integer',
            'desde' => 'date',
            'hasta' => 'date',
            'dias' => 'integer',
        ];
    }
}
