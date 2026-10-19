<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una línea de la prefactura tal como quedó al cerrar el mes: cliente, precio aplicado,
 * cantidad y monto. Es copia, no referencia: no cambia si después cambia el precio.
 *
 * @property int $id
 * @property int $cierre_id
 * @property string $cliente_id
 * @property string $cliente_nombre
 * @property int $tarifa_id
 * @property string $servicio
 * @property string $unidad
 * @property string $origen
 * @property string $precio
 * @property string $moneda
 * @property string $simbolo
 * @property int $decimales
 * @property Carbon $vigente_desde
 * @property ?Carbon $vigente_hasta
 * @property string $cantidad
 * @property int $dias
 * @property int $hechos
 * @property string $monto
 */
class LineaCierre extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_cierres_lineas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tarifa_id' => 'integer',
            'decimales' => 'integer',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'dias' => 'integer',
            'hechos' => 'integer',
        ];
    }
}
