<?php

use App\Finanzas\Http\Controllers\CapturasFinanzasController;
use App\Finanzas\Http\Controllers\HechosFinanzasController;
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
});
