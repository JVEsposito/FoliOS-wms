<?php

return [
    'conexion_operacion' => env('DB_CONNECTION', 'mysql'),
    'conexion_lectura' => env('RRHH_CONEXION_LECTURA', 'operacion_lectura'),
    'maximo_horas_asignacion' => 24,
    'maximo_marcajes_importacion' => 1000,
];
