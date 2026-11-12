<?php

return [
    // Con «true», el cierre exige que cada pallet esté registrado como cargado
    // en el camión. Activar cuando todas las tablets del andén estén actualizadas.
    'cierre_exige_pallets_cargados' => (bool) env('DESPACHO_CIERRE_EXIGE_CARGADOS', false),

    // Con «true», cerrar la planilla R-24 de consolidación exige las fotos obligatorias del
    // set de consolidación (FormularioControlDespacho::tiposFoto), oficial desde el 12-11-2026.
    // «false» las deja como pendientes sin bloquear el cierre.
    'control_exige_fotos' => (bool) env('CONTROL_DESPACHO_EXIGE_FOTOS', true),
];
