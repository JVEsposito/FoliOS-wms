<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un hecho que explica una diferencia: llegó después del cierre (nuevo), cambió su cantidad o
 * sus días (corregido) o dejó de cobrarse en esa línea (retirado). Cantidad y días con signo.
 *
 * @property int $id
 * @property int $diferencia_id
 * @property string $fuente
 * @property string $origen_id
 * @property ?int $hecho_id
 * @property ?string $referencia
 * @property string $tipo
 * @property ?Carbon $desde
 * @property ?Carbon $hasta
 * @property int $dias
 * @property string $cantidad
 */
class AporteDiferencia extends Model
{
    public const NUEVO = 'nuevo';

    public const CORREGIDO = 'corregido';

    public const RETIRADO = 'retirado';

    protected $connection = 'finanzas';

    protected $table = 'fin_diferencias_aportes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hecho_id' => 'integer',
            'desde' => 'date',
            'hasta' => 'date',
            'dias' => 'integer',
        ];
    }
}
