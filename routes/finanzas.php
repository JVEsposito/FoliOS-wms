<?php

use App\Finanzas\Http\Controllers\CapturasFinanzasController;
use App\Finanzas\Http\Controllers\CargosFinanzasController;
use App\Finanzas\Http\Controllers\CierresPrefacturaController;
use App\Finanzas\Http\Controllers\CostosComexController as CostosComex;
use App\Finanzas\Http\Controllers\CuentasProveedorController;
use App\Finanzas\Http\Controllers\DiferenciasPrefacturaController;
use App\Finanzas\Http\Controllers\DocumentosCompraController;
use App\Finanzas\Http\Controllers\DotacionFinanzasController;
use App\Finanzas\Http\Controllers\HechosFinanzasController;
use App\Finanzas\Http\Controllers\LibroComprasController;
use App\Finanzas\Http\Controllers\MonedasFinanzasController;
use App\Finanzas\Http\Controllers\PrefacturaFinanzasController;
use App\Finanzas\Http\Controllers\ProveedoresFinanzasController;
use App\Finanzas\Http\Controllers\ServiciosFinanzasController;
use App\Finanzas\Http\Controllers\TarifasFinanzasController;
use Illuminate\Support\Facades\Route;

/*
 * Finanzas. Estas rutas leen la operación y escriben solo en la base de Finanzas;
 * ninguna modifica registros operativos. Ver docs/FINANZAS.md.
 */
Route::middleware('auth:sanctum')->prefix('finanzas')->group(function (): void {
    Route::prefix('costos-comex')->middleware('can:consultar-costos-comex')->group(function (): void {
        Route::get('/', [CostosComex::class, 'index']);
        Route::get('/bookings', [CostosComex::class, 'bookings']);
        Route::get('/bookings/{booking}', [CostosComex::class, 'booking'])->whereUuid('booking');
        Route::get('/documentos', [CostosComex::class, 'documentos']);
        Route::get('/{costo}', [CostosComex::class, 'show'])->whereUuid('costo');
        Route::get('/{costo}/historial', [CostosComex::class, 'historial'])->whereUuid('costo');
        Route::middleware(['can:gestionar-costos-comex', 'throttle:60,1'])->group(function (): void {
            Route::post('/', [CostosComex::class, 'guardar']);
            Route::put('/{costo}', [CostosComex::class, 'guardar'])->whereUuid('costo');
            Route::post('/{costo}/transiciones', [CostosComex::class, 'transicion'])->whereUuid('costo');
        });
    });

    Route::prefix('proveedores')->middleware('can:consultar-proveedores-finanzas')->group(function (): void {
        Route::get('/', [ProveedoresFinanzasController::class, 'index']);
        Route::get('/cuenta-corriente', [CuentasProveedorController::class, 'cuenta']);
        Route::get('/pagos', [CuentasProveedorController::class, 'pagos']);
        Route::get('/aplicaciones', [CuentasProveedorController::class, 'aplicaciones']);
        Route::get('/mayor', [CuentasProveedorController::class, 'mayor']);
        Route::get('/balance', [CuentasProveedorController::class, 'balance']);
        Route::get('/exportar', [CuentasProveedorController::class, 'exportar'])->middleware('throttle:finanzas-exportaciones');
        Route::get('/evaluaciones', [CuentasProveedorController::class, 'evaluaciones']);
        Route::middleware('can:registrar-pagos-proveedores')->group(function (): void {
            Route::post('/pagos', [CuentasProveedorController::class, 'pagar']);
            Route::post('/pagos/{pago}/anular', [CuentasProveedorController::class, 'anularPago']);
            Route::post('/aplicaciones', [CuentasProveedorController::class, 'aplicar']);
            Route::post('/aplicaciones/{aplicacion}/anular', [CuentasProveedorController::class, 'desaplicar']);
        });
        Route::get('/materiales', [ProveedoresFinanzasController::class, 'materiales']);
        Route::get('/referencias', [DocumentosCompraController::class, 'referencias']);
        Route::get('/asientos', [LibroComprasController::class, 'asientos']);
        Route::get('/historial/{entidad}/{id}', [ProveedoresFinanzasController::class, 'historial'])->whereIn('entidad', ['proveedor', 'documento', 'cuenta', 'plan', 'pago', 'aplicacion', 'evaluacion', 'periodo']);
        Route::get('/documentos', [DocumentosCompraController::class, 'index']);
        Route::get('/documentos/{documento}', [DocumentosCompraController::class, 'show']);
        Route::middleware('can:gestionar-proveedores-finanzas')->group(function (): void {
            Route::post('/', [ProveedoresFinanzasController::class, 'store']);
            Route::put('/fichas/{proveedor}', [ProveedoresFinanzasController::class, 'update']);
            Route::post('/plan/inicializar', [ProveedoresFinanzasController::class, 'plan']);
            Route::post('/evaluaciones', [CuentasProveedorController::class, 'evaluar']);
            Route::post('/evaluaciones/{evaluacion}/anular', [CuentasProveedorController::class, 'anularEvaluacion']);
            Route::post('/periodos', [CuentasProveedorController::class, 'periodo']);
            Route::post('/cuentas', [ProveedoresFinanzasController::class, 'cuenta']);
            Route::post('/documentos', [DocumentosCompraController::class, 'store']);
            Route::put('/documentos/{documento}', [DocumentosCompraController::class, 'update']);
            Route::post('/documentos/{documento}/registrar', [DocumentosCompraController::class, 'registrar']);
            Route::post('/documentos/{documento}/anular', [DocumentosCompraController::class, 'anular']);
        });
    });

    Route::get('/hechos', [HechosFinanzasController::class, 'index'])
        ->middleware('can:consultar-finanzas');
    Route::get('/hechos/{hecho}', [HechosFinanzasController::class, 'show'])
        ->whereNumber('hecho')
        ->middleware('can:consultar-finanzas');
    Route::get('/capturas', [CapturasFinanzasController::class, 'index'])
        ->middleware('can:consultar-finanzas');
    Route::post('/capturas', [CapturasFinanzasController::class, 'store'])
        ->middleware(['can:capturar-hechos-finanzas', 'throttle:6,1']);

    Route::get('/dotacion', [DotacionFinanzasController::class, 'index'])->middleware(['can:consultar-finanzas', 'can:consultar-rrhh']);
    Route::post('/dotacion/cargos', [DotacionFinanzasController::class, 'store'])->middleware(['can:gestionar-tarifas-finanzas', 'can:consultar-rrhh', 'throttle:30,1']);

    Route::get('/cargos', [CargosFinanzasController::class, 'index'])->middleware('can:consultar-finanzas');
    Route::post('/cargos', [CargosFinanzasController::class, 'store'])->middleware('can:gestionar-tarifas-finanzas');
    Route::get('/cargos/{cargo}/historial', [CargosFinanzasController::class, 'historial'])->middleware('can:consultar-finanzas');
    Route::post('/cargos/{cargo}/aprobar', [CargosFinanzasController::class, 'aprobar'])->middleware('can:gestionar-tarifas-finanzas');
    Route::post('/cargos/{cargo}/anular', [CargosFinanzasController::class, 'anular'])->middleware('can:gestionar-tarifas-finanzas');

    Route::post('/servicios', [ServiciosFinanzasController::class, 'store'])
        ->middleware('can:gestionar-tarifas-finanzas');
    Route::get('/servicios/{servicio}/historial', [ServiciosFinanzasController::class, 'historial'])
        ->middleware('can:consultar-finanzas');
    Route::post('/servicios/{servicio}/finalizar', [ServiciosFinanzasController::class, 'finalizar'])
        ->middleware('can:gestionar-tarifas-finanzas');

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
