<?php

namespace App\Enums;

enum TipoResolucionIncidenciaCarga: string
{
    case DespachoParcial = 'despacho_parcial';
    case Reemplazo = 'reemplazo';
    case Reparado = 'reparado';
    /** Cierre automático: el pallet salió de la carga por cancelación o cambio comercial. */
    case RetiradoDeCarga = 'retirado_de_carga';
}
