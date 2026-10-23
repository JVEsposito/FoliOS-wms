<?php

namespace App\Enums;

enum TipoEventoRecepcionMaterial: string
{
    case Creada = 'creada';
    case Actualizada = 'actualizada';
    case CorregidaAdministrativamente = 'corregida_administrativamente';
    case Confirmada = 'confirmada';
    case Regularizada = 'regularizada';
    case Anulada = 'anulada';
}
