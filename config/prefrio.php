<?php

return [
    /*
     * Registro de prefrío POEMP R-41. Cada lectura del túnel toma tres sectores (ventilador,
     * medio y puerta), con sensor exterior e interior a cada lado. Las lecturas horarias
     * pueden quedar parciales, pero al terminar el enfriado se exige una lectura completa.
     */
    'r41' => [
        // Cada cuánto se pide una lectura mientras el túnel enfría.
        'intervalo_lectura_minutos' => (int) env('PREFRIO_INTERVALO_LECTURA_MINUTOS', 60),
        // «false» deja terminar sin lectura final completa (por ejemplo, mientras alguna
        // tablet de Prefrío no tiene la APK con la grilla de sectores).
        'exige_lectura_final' => (bool) env('PREFRIO_EXIGE_LECTURA_FINAL', true),
    ],

    /*
     * Control de condensación por punto de rocío (aproximación de Magnus).
     * El margen mínimo es la diferencia entre la T° superficial de la fruta y el
     * punto de rocío del aire: bajo él se alerta «margen bajo». Es un criterio
     * preventivo interno, no una norma. Supervisión puede ajustarlo por etapa;
     * este valor rige para las etapas sin ajuste.
     */
    'punto_rocio' => [
        'margen_minimo_c' => (float) env('PREFRIO_PUNTO_ROCIO_MARGEN_MINIMO_C', 2.0),
        'dias_registro_retroactivo' => (int) env('PREFRIO_PUNTO_ROCIO_DIAS_RETROACTIVOS', 7),
        // Antigüedad máxima de un sellado o de una medición de origen que se vincula.
        'horas_referencia' => (int) env('PREFRIO_PUNTO_ROCIO_HORAS_REFERENCIA', 72),
        // Temperatura a la que empieza a congelarse la fruta. Referencia para cereza
        // dulce: −1,7 °C (WFLO Commodity Storage Manual). El plan avisa al acercarse.
        'temperatura_congelacion_c' => (float) env('PREFRIO_TEMPERATURA_CONGELACION_C', -1.7),
    ],
];
