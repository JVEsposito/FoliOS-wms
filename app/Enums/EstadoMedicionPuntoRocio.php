<?php

namespace App\Enums;

/** Riesgo de condensación según el margen entre la superficie de la fruta y el punto de rocío. */
enum EstadoMedicionPuntoRocio: string
{
    case SinSuperficie = 'sin_superficie';
    case CondensacionPosible = 'condensacion_posible';
    case MargenBajo = 'margen_bajo';
    case MargenFavorable = 'margen_favorable';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinSuperficie => 'Medir superficie',
            self::CondensacionPosible => 'Condensación posible',
            self::MargenBajo => 'Margen bajo',
            self::MargenFavorable => 'Margen favorable',
        };
    }

    public function esAlerta(): bool
    {
        return $this === self::CondensacionPosible || $this === self::MargenBajo;
    }
}
