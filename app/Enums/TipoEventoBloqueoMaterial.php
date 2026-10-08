<?php

namespace App\Enums;

enum TipoEventoBloqueoMaterial: string
{
    case Bloqueado = 'bloqueado';
    case Liberado = 'liberado';
    case FechaCorregida = 'fecha_corregida';
}
