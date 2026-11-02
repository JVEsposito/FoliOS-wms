<?php

namespace App\Enums;

enum EstadoCargaFolio: string
{
    case Pendiente = 'pendiente';
    case ConIncidencia = 'con_incidencia';
    case EnAnden = 'en_anden';
    /** El pallet subió al camión o contenedor: sigue reservado hasta el cierre. */
    case Cargado = 'cargado';
    case Despachado = 'despachado';
    case Descartado = 'descartado';
    case Reemplazado = 'reemplazado';
    /** Salió despachado y volvió a planta con un retorno de carga. */
    case Retornado = 'retornado';

    public function mantieneReserva(): bool
    {
        return in_array($this, [
            self::Pendiente,
            self::ConIncidencia,
            self::EnAnden,
            self::Cargado,
        ], true);
    }

    /** El pallet ya salió de la cámara: está en el andén o arriba del camión. */
    public function fueraDeCamara(): bool
    {
        return in_array($this, self::fueraDeCamaraActivos(), true);
    }

    /** @return array<int, self> */
    public static function fueraDeCamaraActivos(): array
    {
        return [self::EnAnden, self::Cargado];
    }

    /** @return array<int, string> */
    public static function valoresFueraDeCamara(): array
    {
        return array_map(fn (self $estado): string => $estado->value, self::fueraDeCamaraActivos());
    }
}
