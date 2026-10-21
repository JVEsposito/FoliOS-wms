<?php

use App\Http\Controllers\Api\ContraparteSagController;
use App\Http\Controllers\Api\DocumentoSagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:consultar-contraparte-sag'])->prefix('contraparte-sag')->group(function (): void {
    Route::get('/configuracion', [ContraparteSagController::class, 'configuracion']);
    Route::get('/existencias', [ContraparteSagController::class, 'existencias']);
    Route::get('/folios/{numero}', [DocumentoSagController::class, 'folio']);
    Route::get('/fuentes/{tipo}/{referencia}', [DocumentoSagController::class, 'fuente']);
    Route::get('/en-blanco/{tipo}/{formato}', [DocumentoSagController::class, 'blanco']);
    Route::get('/documentos', [DocumentoSagController::class, 'index']);
    Route::get('/documentos/{documento}', [DocumentoSagController::class, 'show']);
    Route::get('/documentos/{documento}/archivo/{formato}', [DocumentoSagController::class, 'archivo']);
    Route::get('/documentos/{documento}/respaldos/{archivo}', [DocumentoSagController::class, 'respaldo']);
    Route::middleware('can:gestionar-contraparte-sag')->group(function (): void {
        Route::put('/configuracion', [ContraparteSagController::class, 'guardarConfiguracion']);
        Route::post('/responsables', [ContraparteSagController::class, 'guardarResponsable']);
        Route::put('/responsables/{responsable}', [ContraparteSagController::class, 'guardarResponsable']);
        Route::post('/existencias/corte-inicial', [ContraparteSagController::class, 'corte']);
        Route::post('/documentos', [DocumentoSagController::class, 'store']);
        Route::put('/documentos/{documento}', [DocumentoSagController::class, 'update']);
        Route::post('/documentos/{documento}/respaldos', [DocumentoSagController::class, 'adjuntar']);
        Route::post('/documentos/{documento}/{accion}', [DocumentoSagController::class, 'actuar'])->whereIn('accion', ['emitir', 'enviar', 'aprobar', 'rechazar', 'cancelar']);
    });
});
