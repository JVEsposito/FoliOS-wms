<?php

namespace App\Enums;

enum EstadoFolioProcesoPrefrio: string
{
    case Cargado = 'cargado';
    case EnProceso = 'en_proceso';
    case Aprobado = 'aprobado';
    case PendientePrefrio = 'pendiente_prefrio';
    case Retenido = 'retenido';
    case RequiereReproceso = 'requiere_reproceso';
    case Retirado = 'retirado';
    case Cancelado = 'cancelado';
}
