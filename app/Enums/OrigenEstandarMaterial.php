<?php

namespace App\Enums;

/** De dónde viene un estándar de consumo: copiado desde el sistema de planta o definido en FoliOS. */
enum OrigenEstandarMaterial: string
{
    case Planta = 'planta';
    case Propio = 'propio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Planta => 'Planta',
            self::Propio => 'Propio',
        };
    }
}
