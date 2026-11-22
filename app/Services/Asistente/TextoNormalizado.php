<?php

namespace App\Services\Asistente;

use Illuminate\Support\Str;

/**
 * Forma comparable de un texto: minúsculas, sin tildes y con espacios simples.
 */
final class TextoNormalizado
{
    public static function de(string $texto): string
    {
        $ascii = Str::ascii(mb_strtolower($texto));

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }

    /** @return list<string> */
    public static function palabras(string $texto): array
    {
        $limpio = (string) preg_replace('/[^a-z0-9\-\/ ]+/', ' ', self::de($texto));

        return array_values(array_filter(explode(' ', $limpio), fn (string $palabra): bool => $palabra !== ''));
    }
}
