<?php

namespace App\Enums;

enum OrigenVersionOrdenProcesoMp: string
{
    /** Publicada por el digitador desde la oficina. */
    case Digitador = 'digitador';

    /** Creada al autorizar la entrega de un lote que no estaba en la orden. */
    case FueraDeOrden = 'fuera_de_orden';
}
