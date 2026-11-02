<?php

namespace App\Enums;

enum EstadoCarga: string
{
    case Borrador = 'borrador';
    case Pendiente = 'pendiente';
    case EnPreparacion = 'en_preparacion';
    case DespachoParcial = 'despacho_parcial';
    case EnSeparacion = 'en_separacion';
    case Separada = 'separada';
    case SeparacionCompleta = 'separacion_completa';
    case Despachada = 'despachada';
    case Cerrada = 'cerrada';
    case Cancelada = 'cancelada';
    /** El camión volvió a planta con toda la carga: los pallets reingresan a cámara. */
    case Retornada = 'retornada';
    /** Volvió solo una parte; el resto quedó despachado. */
    case RetornoParcial = 'retorno_parcial';

    /** @return array<int, self> */
    public static function terminales(): array
    {
        return [self::Cerrada, self::Cancelada, self::Retornada, self::RetornoParcial];
    }

    /** Cargas cuya salida física ocurrió: admiten registrar un retorno. */
    public function admiteRetorno(): bool
    {
        return in_array($this, [self::Cerrada, self::RetornoParcial], true);
    }

    /**
     * @return array<int, self>
     */
    public static function visiblesEnOperacion(): array
    {
        return [
            self::Pendiente,
            self::EnPreparacion,
            self::DespachoParcial,
            self::EnSeparacion,
            self::Separada,
            self::SeparacionCompleta,
        ];
    }
}
