<?php

return [
    'planta' => env('ESTIBA_PLANTA_NOMBRE'),

    // Temas personales de Oficina: cada tema con los correos de las cuentas que lo pueden usar.
    // OFICINA_TEMA_BIT="persona@empresa.cl,otra@empresa.cl" habilita el modo bit y el modo neón.
    'temas_personales' => (function (): array {
        $correos = array_values(array_filter(array_map(
            fn (string $correo): string => mb_strtolower(trim($correo)),
            explode(',', (string) env('OFICINA_TEMA_BIT', '')),
        )));

        return ['dark-bit' => $correos, 'dark-bit-neon' => $correos];
    })(),

    // Temas que cada rol trae siempre, además de los habilitados por correo. El perfil de acceso
    // «Superadmin» se asigna a cuentas con rol Administrador, así que queda cubierto.
    'temas_por_rol' => [
        'administrador' => ['dark-bit', 'dark-bit-neon'],
    ],
];
