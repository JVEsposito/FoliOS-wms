<?php

namespace App\Enums;

enum PrioridadCamara: string
{
    case General = 'general';
    case Despacho = 'despacho';
    case Almacenamiento = 'almacenamiento';
    case SalidaTuneles = 'salida_tuneles';
}
