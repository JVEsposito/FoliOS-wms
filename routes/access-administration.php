<?php

use App\Http\Controllers\Api\AdministracionAccesoController;
use App\Http\Controllers\Api\ConexionExternaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:administrar-accesos'])->group(function (): void {
    Route::put(
        '/administracion/usuarios/{usuario}',
        [AdministracionAccesoController::class, 'actualizarUsuario'],
    );
});

Route::middleware(['auth:sanctum', 'can:administrar-accesos', 'cache.headers:no_store;private'])
    ->prefix('/administracion/conexiones-externas')
    ->group(function (): void {
        Route::get('/', [ConexionExternaController::class, 'index']);
        Route::post('/', [ConexionExternaController::class, 'store']);
        Route::put('/{conexionExterna}', [ConexionExternaController::class, 'update']);
        Route::post('/{conexionExterna}/probar', [ConexionExternaController::class, 'probar'])
            ->middleware('throttle:6,1');
    });
