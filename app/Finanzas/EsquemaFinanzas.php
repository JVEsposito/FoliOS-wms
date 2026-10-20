<?php

namespace App\Finanzas;

use App\Gestion\EsquemaModulo;

/**
 * Base de datos propia de Finanzas. Sus tablas viven fuera del esquema operativo y nunca
 * tienen llaves foráneas hacia él: guardan la referencia y una copia de lo leído.
 */
final class EsquemaFinanzas extends EsquemaModulo
{
    public const CONEXION = 'finanzas';

    /** Primera migración con tablas de Finanzas: si no está registrada, ninguna lo está. */
    public const MIGRACION_INICIAL = '2026_10_17_100100_crear_captura_hechos_finanzas';

    public function nombre(): string
    {
        return 'Finanzas';
    }

    public function conexionNombre(): string
    {
        return self::CONEXION;
    }

    public function prefijo(): string
    {
        return 'fin_';
    }

    public function migracionInicial(): string
    {
        return self::MIGRACION_INICIAL;
    }

    public function conexionOperacion(): string
    {
        return (string) config('finanzas.conexion_operacion');
    }

    protected function documentacion(): string
    {
        return 'docs/FINANZAS.md';
    }
}
