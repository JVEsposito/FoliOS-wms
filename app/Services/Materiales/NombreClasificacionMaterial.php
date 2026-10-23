<?php

namespace App\Services\Materiales;

use Illuminate\Support\Str;

class NombreClasificacionMaterial
{
    public static function limpiar(string $nombre): string
    {
        return preg_replace('/\s+/u', ' ', trim($nombre));
    }

    public static function normalizar(string $nombre): string
    {
        return mb_strtolower(Str::ascii(self::limpiar($nombre)));
    }
}
