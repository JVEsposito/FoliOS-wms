<?php

namespace App\Enums;

/**
 * Momento del flujo de la fruta en que se mide el riesgo de condensación. Los
 * casos van en el orden de la cadena de frío, del packing al contenedor.
 */
enum EtapaMedicionPuntoRocio: string
{
    case Packing = 'packing';
    case Sellado = 'sellado';
    case Paletizado = 'paletizado';
    case IngresoPrefrio = 'ingreso_prefrio';
    case SalidaPrefrio = 'salida_prefrio';
    case Traslado = 'traslado';
    case Almacenamiento = 'almacenamiento';
    case Despacho = 'despacho';
    case CargaContenedor = 'carga_contenedor';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Packing => 'Packing',
            self::Sellado => 'Sellado',
            self::Paletizado => 'Paletizado',
            self::IngresoPrefrio => 'Ingreso prefrío',
            self::SalidaPrefrio => 'Salida prefrío',
            self::Traslado => 'Traslado',
            self::Almacenamiento => 'Almacenamiento',
            self::Despacho => 'Despacho / andén',
            self::CargaContenedor => 'Carga de contenedor',
            self::Otro => 'Otro',
        };
    }

    /**
     * Etapas que forman la cadena de frío planificable, en orden.
     *
     * @return list<self>
     */
    public static function cadena(): array
    {
        return array_values(array_filter(self::cases(), fn (self $etapa): bool => $etapa !== self::Otro));
    }
}
