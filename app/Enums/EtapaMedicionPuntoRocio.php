<?php

namespace App\Enums;

/** Momento del flujo de la fruta en que se mide el riesgo de condensación. */
enum EtapaMedicionPuntoRocio: string
{
    case Sellado = 'sellado';
    case Paletizado = 'paletizado';
    case IngresoPrefrio = 'ingreso_prefrio';
    case SalidaPrefrio = 'salida_prefrio';
    case Almacenamiento = 'almacenamiento';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sellado => 'Sellado',
            self::Paletizado => 'Paletizado',
            self::IngresoPrefrio => 'Ingreso prefrío',
            self::SalidaPrefrio => 'Salida prefrío',
            self::Almacenamiento => 'Almacenamiento',
            self::Otro => 'Otro',
        };
    }
}
