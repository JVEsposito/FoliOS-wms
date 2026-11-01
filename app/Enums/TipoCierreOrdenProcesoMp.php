<?php

namespace App\Enums;

enum TipoCierreOrdenProcesoMp: string
{
    case Completa = 'completa';
    case Cancelacion = 'cancelacion';
}
