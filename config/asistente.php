<?php

/*
 * Asistente de Oficina, fase B (docs/ASISTENTE_OFICINA.md y docs/ASISTENTE_ESTUDIO_HAIKU.md).
 * La clave de la API va solo en el .env del servidor, nunca en la base de datos.
 */
return [
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'url' => env('ASISTENTE_ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages'),
        'version' => '2023-06-01',
        'modelo' => env('ASISTENTE_MODELO', 'claude-haiku-5-5'),
        'timeout' => (int) env('ASISTENTE_TIMEOUT', 10),
        'max_tokens' => 400,
    ],

    // Topes: al llegar a cualquiera, el asistente responde sin IA y dice por qué.
    'topes' => [
        'mensual_usd' => (float) env('ASISTENTE_TOPE_MENSUAL_USD', 20),
        'diario_usuario' => (int) env('ASISTENTE_TOPE_DIARIO_USUARIO', 50),
    ],

    // US$ por millón de tokens de claude-haiku-5-5, prompts de hasta 100.000 tokens.
    'precios' => [
        'entrada' => 0.10,
        'salida' => 0.50,
        'cache_lectura' => 0.01,
        'cache_escritura' => 0.125,
    ],
];
