<?php

// Plan inicial orientativo: se instala explícitamente desde la pantalla de Proveedores.
// IVA soportado pendiente de revisión NO equivale a crédito fiscal declarado ante el SII.
return [
    'pasivo' => '2101',
    'iva' => '1108',
    'cuentas_iniciales' => [
        ['codigo' => '1101', 'nombre' => 'Banco / pagos registrados', 'tipo' => 'tesoreria'],
        ['codigo' => '1108', 'nombre' => 'IVA soportado por revisar', 'tipo' => 'iva'],
        ['codigo' => '1201', 'nombre' => 'Existencias adquiridas', 'tipo' => 'activo'],
        ['codigo' => '1301', 'nombre' => 'Activo fijo adquirido', 'tipo' => 'activo'],
        ['codigo' => '2101', 'nombre' => 'Cuentas por pagar a proveedores', 'tipo' => 'pasivo'],
        ['codigo' => '5101', 'nombre' => 'Gastos de operación', 'tipo' => 'gasto'],
    ],
];
