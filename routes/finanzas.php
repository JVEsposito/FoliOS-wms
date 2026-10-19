<?php

use App\Finanzas\Http\Controllers\CapturasFinanzasController;
use App\Finanzas\Http\Controllers\HechosFinanzasController;
use App\Finanzas\Http\Controllers\MonedasFinanzasController;
use App\Finanzas\Http\Controllers\TarifasFinanzasController;
use Illuminate\Support\Facades\Route;

/*
 * Finanzas. Estas rutas leen la operación y escriben solo en la base de Finanzas;
 * ninguna modifica registros operativos. Ver docs/FINANZAS.md.
 */
Route::middleware('auth:sanctum')->prefix('finanzas')->group(function (): void {
    Route::get('/hechos', [HechosFinanzasController::class, 'index'])
        ->middleware('can:consultar-finanzas');
    Route::get('/hechos/{hecho}', [HechosFinanzasController::class, 'show'])
        ->whereNumber('hecho')
        ->middleware('can:consultar-finanzas');
    Route::get('/capturas', [CapturasFinanzasController::class, 'index'])
        ->middleware('can:consultar-finanzas');
    Route::post('/capturas', [CapturasFinanzasController::class, 'store'])
        ->middleware(['can:capturar-hechos-finanzas', 'throttle:6,1']);

    // Tarifario: lo consulta quien ve Finanzas; lo edita solo Administración.
    Route::get('/tarifas', [TarifasFinanzasController::class, 'index'])
        ->middleware('can:consultar-finanzas');
    Route::get('/tarifas/efectiva', [TarifasFinanzasController::class, 'efectiva'])
        ->middleware('can:consultar-finanzas');
    Route::get('/tarifas/{tarifa}', [TarifasFinanzasController::class, 'show'])
        ->whereNumber('tarifa')
        ->middleware('can:consultar-finanzas');
    Route::post('/tarifas', [TarifasFinanzasController::class, 'store'])
        ->middleware('can:gestionar-tarifas-finanzas');
    Route::put('/tarifas/{tarifa}', [TarifasFinanzasController::class, 'update'])
        ->whereNumber('tarifa')
        ->middleware('can:gestionar-tarifas-finanzas');
    Route::post('/tarifas/{tarifa}/anular', [TarifasFinanzasController::class, 'anular'])
        ->whereNumber('tarifa')
        ->middleware('can:gestionar-tarifas-finanzas');
    Route::post('/monedas', [MonedasFinanzasController::class, 'store'])
        ->middleware('can:gestionar-tarifas-finanzas');
    Route::put('/monedas/{moneda}', [MonedasFinanzasController::class, 'update'])
        ->where('moneda', '[A-Za-z0-9]{2,10}')
        ->middleware('can:gestionar-tarifas-finanzas');
});
