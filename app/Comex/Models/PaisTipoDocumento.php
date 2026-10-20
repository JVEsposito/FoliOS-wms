<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Destino que exige un documento. Se guarda el código ISO del país: es estable entre
 * ambientes, a diferencia del identificador interno del catálogo operativo.
 *
 * @property int $id
 * @property int $tipo_documento_id
 * @property string $pais_iso2
 * @property string $pais_nombre
 */
class PaisTipoDocumento extends Model
{
    public $timestamps = false;

    protected $connection = 'comex';

    protected $table = 'cx_tipos_documento_paises';

    protected $guarded = ['id'];
}
