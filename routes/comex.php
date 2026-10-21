<?php

use App\Comex\Http\Controllers\CatalogoComercialController as Catalogo;
use App\Comex\Http\Controllers\ConfiguracionComexController;
use App\Comex\Http\Controllers\DocumentosComexController;
use App\Comex\Http\Controllers\ExpedientesComexController;
use App\Comex\Http\Controllers\NotasVentaController as Notas;
use Illuminate\Support\Facades\Route;

/*
 * Comex. Estas rutas leen la operación y escriben solo en la base de Comex; ninguna modifica
 * registros operativos. Ver docs/COMEX.md.
 */
Route::middleware('auth:sanctum')->prefix('comex')->group(function (): void {
    Route::middleware('can:consultar-comercial-comex')->prefix('comercial')->group(function (): void {
        Route::get('/notas', [Notas::class, 'index']);
        Route::get('/notas/{nota}', [Notas::class, 'show'])->whereUuid('nota');
        Route::get('/notas/{nota}/revisiones', [Notas::class, 'revisiones'])->whereUuid('nota');
        Route::get('/notas/{nota}/historial', [Notas::class, 'historial'])->whereUuid('nota');
        Route::get('/contexto', [Catalogo::class, 'contexto']);
        Route::get('/partes', [Catalogo::class, 'partes']);
        Route::get('/lugares', [Catalogo::class, 'lugares']);
        Route::get('/clientes-operacion', [Catalogo::class, 'clientes']);
        Route::get('/historial', [Catalogo::class, 'historial']);
        Route::middleware(['can:gestionar-comercial-comex', 'throttle:comex-comercial'])->group(function (): void {
            Route::post('/notas', [Notas::class, 'guardar']);
            Route::put('/notas/{nota}/borrador', [Notas::class, 'guardar'])->whereUuid('nota');
            Route::post('/notas/{nota}/transiciones', [Notas::class, 'transicion'])->whereUuid('nota');
            Route::post('/partes', [Catalogo::class, 'guardarParte']);
            Route::put('/partes/{parte}', [Catalogo::class, 'guardarParte'])->whereUuid('parte');
            Route::post('/lugares', [Catalogo::class, 'guardarLugar']);
            Route::put('/lugares/{lugar}', [Catalogo::class, 'guardarLugar'])->whereUuid('lugar');
        });
    });

    $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    // Expedientes de embarque: el panel y el detalle los ve quien consulta Comex.
    Route::get('/expedientes', [ExpedientesComexController::class, 'index'])
        ->middleware('can:consultar-comex');
    Route::get('/expedientes/{embarque}', [ExpedientesComexController::class, 'show'])
        ->where('embarque', $uuid)
        ->middleware('can:consultar-comex');
    Route::get('/expedientes/{embarque}/packing-list', [ExpedientesComexController::class, 'packingList'])
        ->where('embarque', $uuid)
        ->middleware(['can:consultar-comex', 'throttle:comex-exportaciones']);

    // Documentos: se registran con su respaldo; reemplazar uno deja el anterior en el historial.
    Route::post('/expedientes/{embarque}/documentos/{tipo}', [DocumentosComexController::class, 'store'])
        ->where('embarque', $uuid)
        ->whereNumber('tipo')
        ->middleware(['can:gestionar-documentos-comex', 'throttle:comex-documentos']);
    Route::get('/expedientes/{embarque}/documentos/{tipo}/archivo', [DocumentosComexController::class, 'archivoVigente'])
        ->where('embarque', $uuid)
        ->whereNumber('tipo')
        ->middleware(['can:consultar-comex', 'throttle:comex-archivos']);
    Route::get('/archivos/{archivo}', [DocumentosComexController::class, 'archivo'])
        ->where('archivo', $uuid)
        ->middleware(['can:consultar-comex', 'throttle:comex-archivos']);

    // Configuración: la ve quien consulta Comex; la cambia quien tiene el módulo de configuración.
    Route::get('/configuracion', [ConfiguracionComexController::class, 'index'])
        ->middleware('can:consultar-comex');
    Route::post('/configuracion/tipos', [ConfiguracionComexController::class, 'storeTipo'])
        ->middleware(['can:configurar-comex', 'throttle:comex-configuracion']);
    Route::post('/configuracion/sugeridos', [ConfiguracionComexController::class, 'storeSugeridos'])
        ->middleware(['can:configurar-comex', 'throttle:comex-configuracion']);
    Route::put('/configuracion/tipos/{tipo}', [ConfiguracionComexController::class, 'updateTipo'])
        ->whereNumber('tipo')
        ->middleware(['can:configurar-comex', 'throttle:comex-configuracion']);
    Route::put('/configuracion/clientes/{cliente}', [ConfiguracionComexController::class, 'updateCliente'])
        ->where('cliente', $uuid)
        ->middleware(['can:configurar-comex', 'throttle:comex-configuracion']);
});
