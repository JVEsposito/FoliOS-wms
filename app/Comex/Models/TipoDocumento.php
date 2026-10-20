<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un documento que Comex sigue en los embarques y las reglas que dicen cuándo se exige:
 * modalidad de transporte, gestión del cliente, destinos y si se necesita antes de la salida.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property ?string $descripcion
 * @property string $momento
 * @property string $exigencia
 * @property list<string> $transportes
 * @property list<string> $gestiones
 * @property bool $pide_numero
 * @property bool $pide_fecha
 * @property bool $pide_archivo
 * @property ?string $dato_operacion
 * @property int $orden
 * @property bool $activo
 * @property bool $sugerido
 * @property int $version
 */
class TipoDocumento extends Model
{
    public const ANTES_SALIDA = 'antes_salida';

    public const DESPUES_SALIDA = 'despues_salida';

    public const MOMENTOS = [self::ANTES_SALIDA, self::DESPUES_SALIDA];

    public const SIEMPRE = 'siempre';

    public const PAISES = 'paises';

    public const OPCIONAL = 'opcional';

    public const EXIGENCIAS = [self::SIEMPRE, self::PAISES, self::OPCIONAL];

    public const TRANSPORTES = ['maritimo', 'aereo', 'terrestre'];

    /** Datos del embarque que la operación ya registra y se muestran como referencia. */
    public const DATOS_OPERACION = [
        'instructivo' => 'N° de instructivo',
        'booking' => 'Booking',
        'dus' => 'DUS',
        'planilla_sag' => 'Planilla SAG',
        'sello_sag' => 'Sello SAG',
        'sps' => 'SPS',
        'contenedor' => 'Contenedor',
        'sello' => 'Sello',
    ];

    protected $connection = 'comex';

    protected $table = 'cx_tipos_documento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transportes' => 'array',
            'gestiones' => 'array',
            'pide_numero' => 'boolean',
            'pide_fecha' => 'boolean',
            'pide_archivo' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
            'sugerido' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<PaisTipoDocumento, $this> */
    public function paises(): HasMany
    {
        return $this->hasMany(PaisTipoDocumento::class, 'tipo_documento_id')->orderBy('pais_nombre');
    }
}
