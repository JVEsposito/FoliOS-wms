<?php

use App\Http\Controllers\Api\ControlDespachoController;
use Illuminate\Support\Facades\Route;

// Planilla POEMP R-24 de consolidación: la PDA la registra, oficina la consulta.
Route::middleware(['auth:sanctum', 'can:consultar-control-despacho'])->prefix('control-despacho')->group(function (): void {
    Route::get('/catalogo', [ControlDespachoController::class, 'catalogo']);
    Route::get('/cargas', [ControlDespachoController::class, 'cargas']);
    Route::get('/controles', [ControlDespachoController::class, 'index']);
    Route::get('/controles/{controlDespacho}', [ControlDespachoController::class, 'show'])->whereUuid('controlDespacho');
    Route::get('/controles/{controlDespacho}/planilla.xlsx', [ControlDespachoController::class, 'planilla'])->whereUuid('controlDespacho');
    Route::get('/controles/{controlDespacho}/fotos/{foto}/{variante}', [ControlDespachoController::class, 'foto'])
        ->whereUuid(['controlDespacho', 'foto'])->whereIn('variante', ['original', 'miniatura']);

    Route::middleware('can:registrar-control-despacho')->group(function (): void {
        Route::post('/cargas/{carga}/control', [ControlDespachoController::class, 'iniciar'])->whereUuid('carga');
        Route::put('/controles/{controlDespacho}', [ControlDespachoController::class, 'update'])->whereUuid('controlDespacho');
        Route::post('/controles/{controlDespacho}/posiciones', [ControlDespachoController::class, 'registrarPosicion'])->whereUuid('controlDespacho');
        Route::delete('/controles/{controlDespacho}/posiciones/{posicion}', [ControlDespachoController::class, 'quitarPosicion'])->whereUuid(['controlDespacho', 'posicion']);
        Route::post('/controles/{controlDespacho}/cerrar', [ControlDespachoController::class, 'cerrar'])->whereUuid('controlDespacho');
        Route::post('/controles/{controlDespacho}/temperaturas', [ControlDespachoController::class, 'completarTemperaturas'])->whereUuid('controlDespacho');
        Route::post('/controles/{controlDespacho}/fotos', [ControlDespachoController::class, 'subirFoto'])->whereUuid('controlDespacho');
        Route::delete('/controles/{controlDespacho}/fotos/{foto}', [ControlDespachoController::class, 'eliminarFoto'])->whereUuid(['controlDespacho', 'foto']);
    });
    Route::post('/controles/{controlDespacho}/reabrir', [ControlDespachoController::class, 'reabrir'])
        ->whereUuid('controlDespacho')->middleware('can:reabrir-control-despacho');
});
