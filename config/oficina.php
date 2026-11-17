<?php

return [
    'planta' => env('ESTIBA_PLANTA_NOMBRE'),

    // Temas personales de Oficina: cada tema con los correos de las cuentas que lo pueden usar.
    // Ej.: OFICINA_TEMA_BIT="persona@empresa.cl,otra@empresa.cl"
    'temas_personales' => [
        'dark-bit' => array_values(array_filter(array_map(
            fn (string $correo): string => mb_strtolower(trim($correo)),
            explode(',', (string) env('OFICINA_TEMA_BIT', '')),
        ))),
    ],
];
