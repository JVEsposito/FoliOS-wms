<?php

namespace App\Enums;

/**
 * Cómo se descuenta el total de un insumo. «Entero» descuenta unidades completas y
 * arrastra la fracción a la próxima rebaja; «Decimal» descuenta la cantidad exacta.
 * No describe la cantidad por caja, que puede tener decimales en ambos casos.
 */
enum RedondeoEstandarMaterial: string
{
    case Entero = 'entero';
    case Decimal = 'decimal';

    public static function desdeTexto(?string $valor): ?self
    {
        return match (mb_strtolower(trim((string) $valor))) {
            'entero' => self::Entero,
            'decimal' => self::Decimal,
            default => null,
        };
    }
}
