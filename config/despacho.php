<?php

return [
    // Con «true», el cierre exige que cada pallet esté registrado como cargado
    // en el camión. Activar cuando todas las tablets del andén estén actualizadas.
    'cierre_exige_pallets_cargados' => (bool) env('DESPACHO_CIERRE_EXIGE_CARGADOS', false),
];
