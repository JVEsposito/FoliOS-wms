<?php

namespace App\Enums;

/** Observación directa de gotas en la fruta o en la bolsa al momento de medir. */
enum GotasVisiblesPuntoRocio: string
{
    case Si = 'si';
    case No = 'no';
    case Pendiente = 'pendiente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Si => 'Sí',
            self::No => 'No',
            self::Pendiente => 'Pendiente',
        };
    }
}
