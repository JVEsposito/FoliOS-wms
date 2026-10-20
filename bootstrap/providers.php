<?php

use App\Comex\ComexServiceProvider;
use App\Finanzas\FinanzasServiceProvider;
use App\Gestion\GestionServiceProvider;
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
    GestionServiceProvider::class,
    FinanzasServiceProvider::class,
    ComexServiceProvider::class,
];
