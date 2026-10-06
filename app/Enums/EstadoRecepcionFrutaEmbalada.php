<?php

namespace App\Enums;

enum EstadoRecepcionFrutaEmbalada: string
{
    case Borrador = 'borrador';
    case Aceptada = 'aceptada';
    case Anulada = 'anulada';
}
