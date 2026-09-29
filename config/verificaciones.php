<?php

return [
    'habilitada' => (bool) env('VERIFICACIONES_HABILITADAS', false),
    'posiciones_por_ronda' => (int) env('VERIFICACIONES_POSICIONES_POR_RONDA', 5),
    'turnos' => [
        'inicio' => env('VERIFICACIONES_TURNOS_INICIO', '06:00'),
        'duracion_horas' => (int) env('VERIFICACIONES_TURNOS_DURACION_HORAS', 8),
    ],
    'dias_sin_repetir' => (int) env('VERIFICACIONES_DIAS_SIN_REPETIR', 7),
];
