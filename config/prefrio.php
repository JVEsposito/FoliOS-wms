<?php

return [
    /*
     * Control de condensación por punto de rocío (aproximación de Magnus).
     * El margen mínimo es la diferencia entre la T° superficial de la fruta y el
     * punto de rocío del aire: bajo él se alerta «margen bajo». Es un criterio
     * preventivo interno, no una norma.
     */
    'punto_rocio' => [
        'margen_minimo_c' => (float) env('PREFRIO_PUNTO_ROCIO_MARGEN_MINIMO_C', 2.0),
        'dias_registro_retroactivo' => (int) env('PREFRIO_PUNTO_ROCIO_DIAS_RETROACTIVOS', 7),
    ],
];
