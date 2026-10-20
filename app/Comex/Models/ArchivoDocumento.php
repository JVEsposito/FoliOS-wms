<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Respaldo de un documento. Reemplazarlo no lo borra: queda en el historial.
 *
 * @property string $id
 * @property int $documento_id
 * @property string $ruta
 * @property string $nombre_original
 * @property string $mime
 * @property int $bytes
 * @property string $sha256
 * @property ?int $subido_por_user_id
 * @property ?string $subido_por_nombre
 * @property Carbon $subido_at
 * @property ?Carbon $reemplazado_at
 */
class ArchivoDocumento extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $connection = 'comex';

    protected $table = 'cx_documentos_archivos';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'subido_at' => 'datetime',
            'reemplazado_at' => 'datetime',
        ];
    }
}
