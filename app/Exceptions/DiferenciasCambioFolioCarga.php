<?php

namespace App\Exceptions;

use DomainException;

/** El pallet de reemplazo difiere del original: oficina debe confirmar el cambio. */
class DiferenciasCambioFolioCarga extends DomainException
{
    /** @param  array<int, array{codigo: string, titulo: string, mensaje: string}>  $advertencias */
    public function __construct(public readonly array $advertencias)
    {
        parent::__construct('El pallet de reemplazo no coincide con el original. Confirma el cambio para continuar.');
    }
}
