<?php

return [
    'reposicion' => ['dias_consumo' => (int) env('MATERIALES_REPOSICION_DIAS_CONSUMO', 30)],
    'dias_alerta_vencimiento' => (int) env('MATERIALES_DIAS_ALERTA_VENCIMIENTO', 30),
];
