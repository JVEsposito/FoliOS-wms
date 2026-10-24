<?php

namespace App\Enums;

enum FuncionCamarero: string
{
    case RecepcionTuneles = 'recepcion_tuneles';
    case RecepcionPacking = 'recepcion_packing';
    case SeparacionCargas = 'separacion_cargas';
    case PreparacionSag = 'preparacion_sag';
    case RecepcionRepa = 'recepcion_repa';
    case ApoyoCamaras = 'apoyo_camaras';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RecepcionTuneles => 'Recibir túneles', self::RecepcionPacking => 'Recibir packing externo',
            self::SeparacionCargas => 'Separar cargas', self::PreparacionSag => 'Preparar lotes SAG',
            self::RecepcionRepa => 'Recibir repaletizaje', self::ApoyoCamaras => 'Apoyar operación de cámaras',
        };
    }
}
