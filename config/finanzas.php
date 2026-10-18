<?php

/*
|--------------------------------------------------------------------------
| Finanzas
|--------------------------------------------------------------------------
|
| Finanzas lee la operación y escribe solo en su propio esquema (conexión
| «finanzas»). La operación no conoce a Finanzas: no hay llaves foráneas
| hacia las tablas operativas ni escrituras sobre ellas. Ver docs/FINANZAS.md.
|
*/

return [

    // Identidad estable: Migrator cambia temporalmente database.default al usar --database.
    'conexion_operacion' => env('DB_CONNECTION', 'mysql'),

    // Conexión por la que Finanzas lee la operación. En producción es una sesión
    // de solo lectura (y, idealmente, un usuario con permiso SELECT y nada más).
    'conexion_lectura' => env('FINANZAS_CONEXION_LECTURA', 'operacion_lectura'),

    'captura' => [
        // La captura programada corre cada 10 minutos; se puede pausar sin tocar la operación.
        'habilitada' => (bool) env('FINANZAS_CAPTURA_HABILITADA', true),

        // Cada lectura vuelve a revisar este margen hacia atrás, para no perder registros
        // confirmados con una hora anterior a la última lectura. Releer es inofensivo:
        // un hecho sin cambios no crea una versión nueva.
        'margen_minutos' => (int) env('FINANZAS_CAPTURA_MARGEN_MINUTOS', 15),

        // Registros por lote al escribir hechos.
        'lote' => 500,
    ],

];
