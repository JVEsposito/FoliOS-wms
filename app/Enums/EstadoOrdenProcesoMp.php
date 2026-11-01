<?php

namespace App\Enums;

/**
 * Estado de una orden de proceso de materia prima.
 *
 * El borrador vive solo en la oficina hasta publicar la v1. Mientras está programada
 * o en proceso la orden no se cierra: solo cambia de versión.
 */
enum EstadoOrdenProcesoMp: string
{
    case Programada = 'programada';
    case EnProceso = 'en_proceso';
    case Terminada = 'terminada';

    public function abierta(): bool
    {
        return $this !== self::Terminada;
    }

    /** @return array<int, string> */
    public static function abiertas(): array
    {
        return [self::Programada->value, self::EnProceso->value];
    }
}
