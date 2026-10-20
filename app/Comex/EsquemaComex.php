<?php

namespace App\Comex;

use App\Gestion\EsquemaModulo;

/**
 * Base de datos propia de Comex. Sus tablas viven fuera del esquema operativo y nunca tienen
 * llaves foráneas hacia él: guardan la referencia del embarque y una copia de lo leído.
 */
final class EsquemaComex extends EsquemaModulo
{
    public const CONEXION = 'comex';

    /** Primera migración con tablas de Comex: si no está registrada, ninguna lo está. */
    public const MIGRACION_INICIAL = '2026_10_21_100100_crear_expedientes_comex';

    public function nombre(): string
    {
        return 'Comex';
    }

    public function conexionNombre(): string
    {
        return self::CONEXION;
    }

    public function prefijo(): string
    {
        return 'cx_';
    }

    public function migracionInicial(): string
    {
        return self::MIGRACION_INICIAL;
    }

    public function conexionOperacion(): string
    {
        return (string) config('comex.conexion_operacion');
    }

    protected function documentacion(): string
    {
        return 'docs/COMEX.md';
    }
}
