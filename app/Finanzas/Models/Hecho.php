<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un hecho valorizable: algo que la operación registró y que Finanzas puede cobrar
 * (una recepción, un pallet validado, una estadía en cámara...). Nunca se edita: cada
 * cambio en la operación crea una versión nueva y la anterior deja de ser vigente.
 *
 * @property int $id
 * @property string $fuente
 * @property string $origen_id
 * @property int $version
 * @property bool $vigente
 * @property string $estado
 * @property string $motivo_version
 * @property ?string $cliente_id
 * @property ?string $cliente_nombre
 * @property ?string $temporada_id
 * @property ?string $temporada_codigo
 * @property ?string $referencia
 * @property Carbon $ocurrido_at
 * @property ?Carbon $termina_at
 * @property Carbon $fecha_operacional
 * @property array<string, int|float> $medidas
 * @property array<string, mixed> $datos
 * @property string $huella
 * @property ?int $lectura_id
 * @property Carbon $capturado_at
 */
class Hecho extends Model
{
    public const ESTADO_VIGENTE = 'vigente';

    public const ESTADO_ANULADO = 'anulado';

    /** Primera vez que Finanzas ve el registro. */
    public const MOTIVO_CAPTURA = 'captura';

    /** El registro cambió en la operación (corrección, cierre de una estadía...). */
    public const MOTIVO_CAMBIO = 'cambio';

    /** La operación lo anuló o dejó de cumplir la condición para ser un hecho. */
    public const MOTIVO_ANULACION = 'anulacion';

    /** El registro ya no existe en la operación (por ejemplo, un reinicio de datos de prueba). */
    public const MOTIVO_ELIMINADO = 'eliminado';

    protected $connection = 'finanzas';

    protected $table = 'fin_hechos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'vigente' => 'boolean',
            'ocurrido_at' => 'datetime',
            'termina_at' => 'datetime',
            'fecha_operacional' => 'date',
            'medidas' => 'array',
            'datos' => 'array',
            'capturado_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $consulta */
    public function scopeVigentes(Builder $consulta): void
    {
        $consulta->where('vigente', true);
    }

    /** @return BelongsTo<Lectura, $this> */
    public function lectura(): BelongsTo
    {
        return $this->belongsTo(Lectura::class);
    }
}
