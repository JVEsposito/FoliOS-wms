<?php

return [
    'cache_segundos' => env('GERENCIA_CACHE_SECONDS', 60),
    // Provisional: operación debe confirmar cuándo alertar por antigüedad PT.
    // El último límite define también el umbral de alerta; basta cambiar este 30.
    'antiguedad_pt_tramos_dias' => [7, 14, 30],
];
