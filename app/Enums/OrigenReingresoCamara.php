<?php

namespace App\Enums;

enum OrigenReingresoCamara: string
{
    case CancelacionCarga = 'cancelacion_carga';
    case PalletQuitado = 'pallet_quitado';
    case PalletCambiado = 'pallet_cambiado';
    case RetornoCarga = 'retorno_carga';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CancelacionCarga => 'Carga cancelada',
            self::PalletQuitado => 'Pallet quitado de la carga',
            self::PalletCambiado => 'Pallet cambiado en la carga',
            self::RetornoCarga => 'Carga retornada a planta',
        };
    }
}
