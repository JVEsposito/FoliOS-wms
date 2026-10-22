<?php

namespace App\Enums;

enum TipoMovimientoEnvase: string
{
    case RecepcionFruta = 'recepcion_fruta';
    case SalidaRecepcionFruta = 'salida_recepcion_fruta';
    case ReversionSalidaRecepcionFruta = 'reversion_salida_fruta';
    case RecepcionArriendo = 'recepcion_arriendo';
    case RecepcionCompra = 'recepcion_compra';
    case DespachoCliente = 'despacho_cliente';
    case ReversionDespacho = 'reversion_despacho';
    case CorreccionPropiedad = 'correccion_propiedad';
    case CorreccionCantidad = 'correccion_cantidad';
    case AperturaCuenta = 'apertura_cuenta';
    case AjusteCuenta = 'ajuste_cuenta';
    case ReversionAjusteCuenta = 'reversion_ajuste_cuenta';

    public function esAjusteCuenta(): bool
    {
        return in_array($this, [self::AperturaCuenta, self::AjusteCuenta, self::ReversionAjusteCuenta], true);
    }
}
