<?php

/*
|--------------------------------------------------------------------------
| Comex
|--------------------------------------------------------------------------
|
| Comercio exterior: el expediente de cada embarque (documentos, respaldos y
| packing list). Lee los embarques de la operación y escribe solo en su
| propio esquema (conexión «comex»). La operación no conoce a Comex. Ver
| docs/COMEX.md.
|
*/

return [

    // Identidad estable: Migrator cambia temporalmente database.default al usar --database.
    'conexion_operacion' => env('DB_CONNECTION', 'mysql'),

    // Conexión por la que Comex lee la operación: la misma sesión de solo lectura de Finanzas.
    'conexion_lectura' => env('COMEX_CONEXION_LECTURA', 'operacion_lectura'),

    // Un embarque con documentos de antes de la salida pendientes se marca urgente cuando
    // faltan estos días o menos para su fecha programada.
    'dias_alerta' => (int) env('COMEX_DIAS_ALERTA', 2),

    // Respaldos: PDF, JPG, PNG o WebP de hasta este tamaño.
    'bytes_maximos_archivo' => 10 * 1024 * 1024,

    // Rango máximo del panel, en días.
    'dias_maximos_panel' => 93,

];
