<?php

namespace App\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un mes de prefactura congelado: lo que se cobró, quién lo cerró y con qué lectura de la
 * operación. Reabrirlo no borra nada: la versión queda reabierta y, al volver a cerrar el mes,
 * se guarda una versión nueva.
 *
 * @property int $id
 * @property string $mes
 * @property int $version
 * @property string $estado
 * @property Carbon $cerrado_at
 * @property ?int $cerrado_por_user_id
 * @property ?string $cerrado_por_nombre
 * @property ?Carbon $reabierto_at
 * @property ?int $reabierto_por_user_id
 * @property ?string $reabierto_por_nombre
 * @property ?string $motivo_reapertura
 * @property int $hechos_hasta_id
 * @property ?int $lectura_id
 * @property ?Carbon $lectura_at
 * @property list<array{moneda: string, simbolo: string, decimales: int, monto: string}> $totales
 * @property array<string, int> $resumen
 * @property array{sin_precio: list<array<string, mixed>>, sin_cliente: list<array<string, mixed>>} $pendientes
 * @property ?array<string, mixed> $revision
 */
class Cierre extends Model
{
    public const ESTADO_CERRADO = 'cerrado';

    public const ESTADO_REABIERTO = 'reabierto';

    protected $connection = 'finanzas';

    protected $table = 'fin_cierres';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'cerrado_at' => 'datetime',
            'reabierto_at' => 'datetime',
            'hechos_hasta_id' => 'integer',
            'lectura_at' => 'datetime',
            'totales' => 'array',
            'resumen' => 'array',
            'pendientes' => 'array',
            'revision' => 'array',
        ];
    }

    /** @return HasMany<LineaCierre, $this> */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaCierre::class, 'cierre_id');
    }

    /** @return HasMany<Diferencia, $this> */
    public function diferencias(): HasMany
    {
        return $this->hasMany(Diferencia::class, 'cierre_id');
    }

    public function vigente(): bool
    {
        return $this->estado === self::ESTADO_CERRADO;
    }
}
