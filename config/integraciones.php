<?php

return [
    /*
    | Stock de producto terminado del sistema de origen, leído por una conexión externa
    | (Administración → Accesos → Conexiones externas) para saber qué falta por validar.
    | Solo lectura: FoliOS no escribe en el origen ni crea folios desde estos datos.
    */
    'stock_pt' => [
        // Nombre del perfil de conexión. Vacío: se usa el único perfil activo, si hay uno.
        'conexion' => env('WMS_STOCK_ORIGEN_CONEXION'),
        'tabla' => env('WMS_STOCK_ORIGEN_TABLA', 'obdc_stock'),
        // Bodega del sistema de origen que corresponde a esta planta.
        'bodega' => env('WMS_STOCK_ORIGEN_BODEGA', 'AR01'),
        // Código de temporada del origen (cod_temporada). Vacío: todas las del stock.
        'temporada' => env('WMS_STOCK_ORIGEN_TEMPORADA'),
        'habilitada' => (bool) env('WMS_STOCK_ORIGEN_HABILITADA', true),
        // Una lectura más antigua que esto se muestra como desactualizada.
        'vigencia_minutos' => (int) env('WMS_STOCK_ORIGEN_VIGENCIA_MINUTOS', 20),
        'conservar_lecturas_dias' => 30,
    ],
];
