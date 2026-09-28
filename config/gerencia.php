<?php

return [
    'cache_segundos' => env('GERENCIA_CACHE_SECONDS', 60),
    // Provisional: operación debe confirmar cuándo alertar por antigüedad PT.
    // Mantener exactamente tres enteros crecientes y positivos: [0–7, 8–14, 15–30].
    // El tercero define también el umbral de alerta; basta cambiar este 30.
    'antiguedad_pt_tramos_dias' => [7, 14, 30],
];
