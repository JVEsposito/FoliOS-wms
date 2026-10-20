<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historial de un documento: cada registro, cambio de estado o respaldo subido.
 *
 * @property int $id
 * @property int $documento_id
 * @property string $tipo
 * @property ?string $estado_anterior
 * @property ?string $estado_nuevo
 * @property ?array<string, mixed> $datos
 * @property ?int $user_id
 * @property ?string $user_nombre
 * @property Carbon $created_at
 */
class EventoDocumento extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'comex';

    protected $table = 'cx_documentos_eventos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }
}
