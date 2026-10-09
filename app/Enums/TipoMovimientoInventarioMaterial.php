<?php

namespace App\Enums;

enum TipoMovimientoInventarioMaterial: string
{
    case TransferenciaClienteSalida = 'transferencia_cliente_salida';
    case TransferenciaClienteEntrada = 'transferencia_cliente_entrada';
    case Ingreso = 'ingreso';
    case IngresoRecepcion = 'ingreso_recepcion';
    case AnulacionRecepcion = 'anulacion_recepcion';
    case Despacho = 'despacho';
    case TransferenciaInterna = 'transferencia_interna';
    case ConsumoCentroCosto = 'consumo_centro_costo';
    case AjusteAlmacen = 'ajuste_almacen';
    case Ajuste = 'ajuste';
    case Devolucion = 'devolucion';
    case CorreccionItemSalida = 'correccion_item_salida';
    case CorreccionItemEntrada = 'correccion_item_entrada';
    case ConsumoTransformacion = 'consumo_transformacion';
    case ProduccionTransformacion = 'produccion_transformacion';
    case MermaTransformacion = 'merma_transformacion';
    case ReversaTransformacion = 'reversa_transformacion';
}
