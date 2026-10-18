<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una pasada de captura: cuándo corrió, quién la pidió y qué encontró en cada fuente.
 *
 * @property int $id
 * @property string $origen
 * @property bool $conciliacion
 * @property string $estado
 * @property ?int $solicitada_por_user_id
 * @property ?string $solicitada_por_nombre
 * @property Carbon $iniciada_at
 * @property ?Carbon $terminada_at
 * @property ?int $duracion_ms
 * @property ?array<string, array<string, mixed>> $resumen
 * @property ?string $error
 */
class Lectura extends Model
{
    public const ORIGEN_PROGRAMADA = 'programada';

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_CONSOLA = 'consola';

    public const ESTADO_EN_CURSO = 'en_curso';

    public const ESTADO_COMPLETADA = 'completada';

    /** Al menos una fuente falló; las demás quedaron capturadas. */
    public const ESTADO_CON_ERRORES = 'con_errores';

    /** La lectura no pudo completarse (por ejemplo, sin conexión con la operación). */
    public const ESTADO_FALLIDA = 'fallida';

    protected $connection = 'finanzas';

    protected $table = 'fin_lecturas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conciliacion' => 'boolean',
            'iniciada_at' => 'datetime',
            'terminada_at' => 'datetime',
            'duracion_ms' => 'integer',
            'resumen' => 'array',
        ];
    }
}
