<?php

return [
    /*
     * Con true, Fruta a proceso solo registra viajes contra una orden de proceso. Se activa
     * cuando todas las tablets de cámara tienen la APK con órdenes; mientras tanto las
     * versiones anteriores siguen enviando línea, turno y número de orden como texto.
     */
    'fruta_proceso_exige_orden' => (bool) env('FRUTA_PROCESO_EXIGE_ORDEN', false),

    // Código SAG de la planta (CSP) que encabeza la PRE-SIF.
    'csp_planta' => env('PLANTA_CSP'),
];
