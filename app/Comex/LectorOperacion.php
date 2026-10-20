<?php

namespace App\Comex;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta de Comex hacia las tablas operativas. Lee por la conexión de lectura
 * (config comex.conexion_lectura), que en producción abre la sesión en modo READ ONLY:
 * aunque un error de programación intentara escribir, la base lo rechazaría.
 */
final class LectorOperacion
{
    public function tabla(string $tabla, ?string $alias = null): Builder
    {
        return $this->conexion()->table($alias === null ? $tabla : "{$tabla} as {$alias}");
    }

    public function conexion(): ConnectionInterface
    {
        return DB::connection((string) config('comex.conexion_lectura'));
    }
}
