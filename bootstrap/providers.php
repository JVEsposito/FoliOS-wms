<?php

use App\Finanzas\FinanzasServiceProvider;
use App\Providers\AdministracionUsuarioServiceProvider;
use App\Providers\AnulacionValidacionPalletServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BinRetornoPackingServiceProvider;
use App\Providers\CustodiaDistribuidaMaterialesServiceProvider;
use App\Providers\RepaletizajeServiceProvider;

return [
    'App\\Providers\\ConsultaTrazabilidadServiceProvider',
    AppServiceProvider::class,
    AdministracionUsuarioServiceProvider::class,
    CustodiaDistribuidaMaterialesServiceProvider::class,
    RepaletizajeServiceProvider::class,
    AnulacionValidacionPalletServiceProvider::class,
    BinRetornoPackingServiceProvider::class,
    FinanzasServiceProvider::class,
];
