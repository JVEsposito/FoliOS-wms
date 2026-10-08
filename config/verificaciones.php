<?php

return [
    'habilitada' => (bool) env('VERIFICACIONES_HABILITADAS', false),
    'posiciones_por_ronda' => (int) env('VERIFICACIONES_POSICIONES_POR_RONDA', 5),
    'productos' => [
        'habilitada' => true,
        'posiciones_por_ronda' => (int) env('VERIFICACIONES_POSICIONES_POR_RONDA', 5),
    ],
    'materiales' => [
        'habilitada' => (bool) env('VERIFICACIONES_MATERIALES_HABILITADAS', false),
        'posiciones_por_ronda' => (int) env('VERIFICACIONES_MATERIALES_POSICIONES_POR_RONDA', 5),
        'verificar_cantidad' => (bool) env('VERIFICACIONES_MATERIALES_VERIFICAR_CANTIDAD', true),
        'tolerancia_cantidad_pct' => (float) env('VERIFICACIONES_MATERIALES_TOLERANCIA_CANTIDAD_PCT', 2),
    ],
    'turnos' => [
        'inicio' => env('VERIFICACIONES_TURNOS_INICIO', '06:00'),
        'duracion_horas' => (int) env('VERIFICACIONES_TURNOS_DURACION_HORAS', 8),
    ],
    'dias_sin_repetir' => (int) env('VERIFICACIONES_DIAS_SIN_REPETIR', 7),
];
