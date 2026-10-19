<?php

namespace App\Finanzas;

use Laravel\Telescope\Telescope;

/**
 * Las operaciones masivas de Finanzas (cerrar un mes, comparar un mes cerrado) no se registran
 * en Telescope: registrar consultas con miles de valores las hace varias veces más lentas y
 * llena sus tablas. Sin Telescope instalado, la operación corre igual.
 */
final class SinTelescope
{
    /**
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    public static function ejecutar(callable $operacion): mixed
    {
        return class_exists(Telescope::class) ? Telescope::withoutRecording($operacion) : $operacion();
    }
}
