<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Moneda en que se expresa un precio del tarifario. Se configura en la oficina; una moneda
 * inactiva conserva sus precios, pero no se ofrece para precios nuevos.
 *
 * @property string $codigo
 * @property string $nombre
 * @property string $simbolo
 * @property int $decimales
 * @property bool $activa
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Moneda extends Model
{
    protected $connection = 'finanzas';

    protected $table = 'fin_monedas';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decimales' => 'integer',
            'activa' => 'boolean',
        ];
    }
}
