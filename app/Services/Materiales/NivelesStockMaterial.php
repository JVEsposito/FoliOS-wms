<?php

namespace App\Services\Materiales;

use Illuminate\Validation\ValidationException;

class NivelesStockMaterial
{
    public const CAMPOS = ['stock_minimo', 'punto_reorden', 'stock_maximo'];

    public static function errores(array $niveles): array
    {
        $errores = [];
        foreach (self::CAMPOS as $campo) {
            $valor = $niveles[$campo] ?? null;
            if ($valor !== null && (! is_numeric($valor) || $valor < 0 || $valor > 99999999999.999 || abs((float) $valor - round((float) $valor, 3)) > 0.00001)) {
                $errores[$campo] = 'Indica un nivel no negativo con hasta tres decimales.';
            }
        }
        foreach ([['stock_minimo', 'punto_reorden'], ['punto_reorden', 'stock_maximo'], ['stock_minimo', 'stock_maximo']] as [$menor, $mayor]) {
            if (isset($niveles[$menor], $niveles[$mayor]) && is_numeric($niveles[$menor]) && is_numeric($niveles[$mayor]) && (float) $niveles[$menor] > (float) $niveles[$mayor]) {
                $errores[$menor] = 'Los niveles deben cumplir mínimo ≤ reorden ≤ máximo.';
            }
        }

        return $errores;
    }

    public static function validar(array $niveles): void
    {
        if ($errores = self::errores($niveles)) {
            throw ValidationException::withMessages($errores);
        }
    }
}
