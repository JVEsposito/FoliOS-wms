<?php

use App\Http\Controllers\Api\ContraparteSagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:consultar-contraparte-sag'])->prefix('contraparte-sag')->group(function (): void {
    Route::get('/configuracion', [ContraparteSagController::class, 'configuracion']);
    Route::get('/existencias', [ContraparteSagController::class, 'existencias']);
    Route::middleware('can:gestionar-contraparte-sag')->group(function (): void {
        Route::put('/configuracion', [ContraparteSagController::class, 'guardarConfiguracion']);
        Route::post('/responsables', [ContraparteSagController::class, 'guardarResponsable']);
        Route::put('/responsables/{responsable}', [ContraparteSagController::class, 'guardarResponsable']);
        Route::post('/existencias/corte-inicial', [ContraparteSagController::class, 'corte']);
    });
});
