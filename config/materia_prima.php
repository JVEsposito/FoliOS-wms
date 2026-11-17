<?php

return [
    /*
     * Con true, Fruta a proceso solo registra viajes contra una orden de proceso. Se activa
     * cuando todas las tablets de cámara tienen la APK con órdenes; mientras tanto las
     * versiones anteriores siguen enviando línea, turno y número de orden como texto.
     */
    'fruta_proceso_exige_orden' => (bool) env('FRUTA_PROCESO_EXIGE_ORDEN', false),

    /*
     * Días hacia atrás que Romana y la digitación pueden declarar como fecha real cuando
     * registran después (por ejemplo, la noche que quedó pendiente). Administración no
     * tiene este límite, pero siempre dentro de la temporada activa.
     */
    'dias_fecha_real' => (int) env('MP_DIAS_FECHA_REAL', 3),

    // Código SAG de la planta (CSP) que encabeza la PRE-SIF.
    'csp_planta' => env('PLANTA_CSP'),
];
