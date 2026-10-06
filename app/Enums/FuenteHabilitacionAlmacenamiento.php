<?php

namespace App\Enums;

enum FuenteHabilitacionAlmacenamiento: string
{
    case PrefrioOrigen = 'prefrio_origen';
    case PrefrioAprobado = 'prefrio_aprobado';
    case CondicionHeredadaRepaletizaje = 'condicion_heredada_repaletizaje';
    case DevolucionOperacional = 'devolucion_operacional';
    case ContingenciaAutorizada = 'contingencia_autorizada';
    case RegularizacionManual = 'regularizacion_manual';
    case RegularizacionExistente = 'regularizacion_existente';
}
