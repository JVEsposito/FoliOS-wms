<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cómo sigue Comex los embarques de un cliente de la operación. Sin registro, el cliente se
 * atiende como servicio.
 *
 * @property string $cliente_id
 * @property string $cliente_nombre
 * @property string $gestion
 * @property ?string $observacion
 * @property int $version
 */
class ClienteComex extends Model
{
    /** La planta presta el servicio y sigue los documentos del exportador. */
    public const SERVICIO = 'servicio';

    /** Exportación propia: la empresa es la exportadora. */
    public const PROPIA = 'propia';

    /** Comex no sigue sus embarques. */
    public const SIN_SEGUIMIENTO = 'sin_seguimiento';

    public const GESTIONES = [self::SERVICIO, self::PROPIA, self::SIN_SEGUIMIENTO];

    public $incrementing = false;

    protected $connection = 'comex';

    protected $table = 'cx_clientes';

    protected $primaryKey = 'cliente_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
