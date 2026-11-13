<?php

namespace App\Enums;

enum TipoMovimiento: string
{
    case UbicacionInicial = 'ubicacion_inicial';
    case Reubicacion = 'reubicacion';
    case TrasladoEntreCamaras = 'traslado_entre_camaras';
    case Retiro = 'retiro';
    case Reversion = 'reversion';
    case CorreccionAdministrativa = 'correccion_administrativa';
}
