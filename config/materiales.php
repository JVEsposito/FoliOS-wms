<?php

return [
    'reposicion' => ['dias_consumo' => (int) env('MATERIALES_REPOSICION_DIAS_CONSUMO', 30)],
    'dias_alerta_vencimiento' => (int) env('MATERIALES_DIAS_ALERTA_VENCIMIENTO', 30),
    // Tarja de armado de cajas. Con código de bodega (p. ej. 100), las órdenes de
    // transformación piden turno y máquina, sus pallets toman el folio único de armado
    // (AR + código del ítem + bodega + 5 dígitos) y la etiqueta sale con el formato de
    // tarja. Sin código, las salidas siguen con el correlativo del cliente.
    'armado' => [
        'codigo_bodega' => env('MATERIALES_ARMADO_CODIGO_BODEGA'),
        'nombre_bodega' => env('MATERIALES_ARMADO_NOMBRE_BODEGA', 'BODEGA AGROROSARIO'),
    ],
];
