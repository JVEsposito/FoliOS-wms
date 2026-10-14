<?php

namespace App\Enums;

/** Folio de salida reservado al planificar una orden de transformación. */
enum EstadoFolioSalidaReservado: string
{
    /** Número reservado e imprimible; todavía no existe en inventario. */
    case Reservado = 'reservado';

    /** Se convirtió en el folio real de un lote cerrado. */
    case Usado = 'usado';

    /** No se usó (orden cerrada o cancelada) o su lote se revirtió. */
    case Anulado = 'anulado';
}
