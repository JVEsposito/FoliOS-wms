<?php

namespace App\Enums;

enum EstadoCargaFolio: string
{
    case Pendiente = 'pendiente';
    case ConIncidencia = 'con_incidencia';
    case EnAnden = 'en_anden';
    case Despachado = 'despachado';
    case Descartado = 'descartado';
    case Reemplazado = 'reemplazado';

    public function mantieneReserva(): bool
    {
        return in_array($this, [
            self::Pendiente,
            self::ConIncidencia,
            self::EnAnden,
        ], true);
    }
}
