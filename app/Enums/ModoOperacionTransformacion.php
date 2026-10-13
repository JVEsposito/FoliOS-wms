<?php

namespace App\Enums;

/**
 * Cómo se ejecuta una orden de transformación en la tablet.
 *
 * - PorLote: cada lote pistolea los folios consumidos y calcula su merma (flujo original).
 * - CuadraturaCierre: los componentes se retiran al iniciar, cada folio de salida se
 *   confirma pistoleando su etiqueta reservada y la merma y lo sin armar se cuadran al
 *   cerrar la orden.
 */
enum ModoOperacionTransformacion: string
{
    case PorLote = 'por_lote';
    case CuadraturaCierre = 'cuadratura_cierre';
}
