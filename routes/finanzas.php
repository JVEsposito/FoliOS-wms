<?php

use App\Finanzas\Http\Controllers\CapturasFinanzasController;
use App\Finanzas\Http\Controllers\CierresPrefacturaController;
use App\Finanzas\Http\Controllers\DiferenciasPrefacturaController;
use App\Finanzas\Http\Controllers\HechosFinanzasController;
use App\Finanzas\Http\Controllers\MonedasFinanzasController;
use App\Finanzas\Http\Controllers\PrefacturaFinanzasController;
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

    // Prefactura en paralelo: un mes abierto se calcula al consultarlo; uno cerrado se lee
    // congelado. No emite documentos tributarios.
    Route::get('/prefactura', [PrefacturaFinanzasController::class, 'index'])
        ->middleware('can:consultar-finanzas');
    Route::get('/prefactura/detalle', [PrefacturaFinanzasController::class, 'detalle'])
        ->middleware('can:consultar-finanzas');
    Route::get('/prefactura/exportar', [PrefacturaFinanzasController::class, 'exportar'])
        ->middleware(['can:consultar-finanzas', 'throttle:finanzas-exportaciones']);

    // Cierre mensual: congela el mes. Solo se reabre el último cerrado, y lo hace un Administrador.
    Route::post('/prefactura/cierres', [CierresPrefacturaController::class, 'store'])
        ->middleware(['can:cerrar-prefactura-finanzas', 'throttle:finanzas-cierres']);
    Route::post('/prefactura/cierres/{mes}/reabrir', [CierresPrefacturaController::class, 'reabrir'])
        ->where('mes', '\d{4}-\d{2}')
        ->middleware(['can:reabrir-prefactura-finanzas', 'throttle:finanzas-cierres']);

    // Diferencias después del cierre: se revisan y se deciden una por una.
    Route::get('/prefactura/diferencias', [DiferenciasPrefacturaController::class, 'index'])
        ->middleware(['can:consultar-finanzas', 'throttle:finanzas-diferencias']);
    Route::get('/prefactura/diferencias/resumen', [DiferenciasPrefacturaController::class, 'resumen'])
        ->middleware(['can:consultar-finanzas', 'throttle:finanzas-diferencias']);
    Route::post('/prefactura/diferencias', [DiferenciasPrefacturaController::class, 'store'])
        ->middleware(['can:cerrar-prefactura-finanzas', 'throttle:finanzas-decisiones']);
    Route::post('/prefactura/diferencias/{diferencia}/anular', [DiferenciasPrefacturaController::class, 'anular'])
        ->whereNumber('diferencia')
        ->middleware(['can:cerrar-prefactura-finanzas', 'throttle:finanzas-decisiones']);
});
