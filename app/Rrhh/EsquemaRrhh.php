<?php

namespace App\Rrhh;

use App\Gestion\EsquemaModulo;

final class EsquemaRrhh extends EsquemaModulo
{
    public const CONEXION = 'rrhh';

    public const MIGRACION_INICIAL = '2026_10_22_110100_crear_asistencia_rrhh';

    public function nombre(): string
    {
        return 'Recursos Humanos';
    }

    public function conexionNombre(): string
    {
        return self::CONEXION;
    }

    public function prefijo(): string
    {
        return 'rh_';
    }

    public function migracionInicial(): string
    {
        return self::MIGRACION_INICIAL;
    }

    public function conexionOperacion(): string
    {
        return (string) config('rrhh.conexion_operacion');
    }

    protected function documentacion(): string
    {
        return 'docs/RRHH.md';
    }
}
