<?php

use App\Http\Controllers\Api\AdministracionAccesoController;
use App\Http\Controllers\Api\ConexionExternaController;
use App\Http\Controllers\Api\ConfiguracionStockOrigenController;
use App\Http\Controllers\Api\ExploracionOrigenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:administrar-accesos'])->group(function (): void {
    Route::put(
        '/administracion/usuarios/{usuario}',
        [AdministracionAccesoController::class, 'actualizarUsuario'],
    );
});

Route::middleware(['auth:sanctum', 'can:administrar-accesos'])
    ->prefix('/administracion/conexiones-externas')
    ->group(function (): void {
        Route::get('/', [ConexionExternaController::class, 'index']);
        Route::post('/', [ConexionExternaController::class, 'store']);
        Route::get('/lectura-stock', [ConfiguracionStockOrigenController::class, 'index']);
        Route::put('/lectura-stock', [ConfiguracionStockOrigenController::class, 'guardar'])->middleware('throttle:12,1');
        Route::post('/lectura-stock/simular', [ConfiguracionStockOrigenController::class, 'simular'])->middleware('throttle:12,1');
        Route::put('/{conexionExterna}', [ConexionExternaController::class, 'update']);
        Route::post('/{conexionExterna}/probar', [ConexionExternaController::class, 'probar'])
            ->middleware('throttle:6,1');
        Route::post('/{conexionExterna}/estructura', [ExploracionOrigenController::class, 'estructura'])->middleware('throttle:12,1');
        Route::post('/{conexionExterna}/columnas', [ExploracionOrigenController::class, 'columnas'])->middleware('throttle:24,1');
        Route::post('/{conexionExterna}/muestra', [ExploracionOrigenController::class, 'muestra'])->middleware('throttle:12,1');
    });
