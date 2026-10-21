<?php

namespace App\Finanzas\Tarifario;

/** Precio unitario como texto decimal exacto: hasta 12 enteros y 6 decimales, sin flotantes. */
final class Precio
{
    public const FORMATO = '/^-?\d{1,12}(\.\d{1,6})?$/';

    /** «0.350000» → «0.35»; «012» → «12»; «5.000000» → «5». */
    public static function normalizar(string $precio): string
    {
        $precio = trim($precio);
        $signo = str_starts_with($precio, '-') ? '-' : '';
        [$entero, $decimales] = array_pad(explode('.', ltrim($precio, '-'), 2), 2, '');
        $entero = ltrim($entero, '0') ?: '0';
        $decimales = rtrim($decimales, '0');

        $valor = $decimales === '' ? $entero : "{$entero}.{$decimales}";

        return $valor === '0' ? '0' : $signo.$valor;
    }
}
