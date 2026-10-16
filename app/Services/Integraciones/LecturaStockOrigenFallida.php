<?php

namespace App\Services\Integraciones;

use RuntimeException;

/** Falla controlada de una lectura del stock de origen, con causa y mensaje para la oficina. */
class LecturaStockOrigenFallida extends RuntimeException
{
    public function __construct(public readonly string $causa, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
