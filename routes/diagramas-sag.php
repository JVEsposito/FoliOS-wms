<?php

use App\Http\Controllers\Api\DiagramaPalletsSagController;
use Illuminate\Support\Facades\Route;

// Diagramas de presentación de pallets SAG/USDA: se consultan y emiten con Inspección SAG; guardar exige gestionarla.
Route::middleware(['auth:sanctum', 'can:consultar-inspeccion-sag'])->prefix('sag/diagramas')->group(function (): void {
    Route::get('/catalogo', [DiagramaPalletsSagController::class, 'catalogo']);
    Route::get('/', [DiagramaPalletsSagController::class, 'index']);
    Route::post('/pdf', [DiagramaPalletsSagController::class, 'pdf']);
    Route::get('/{diagrama}', [DiagramaPalletsSagController::class, 'show'])->whereUuid('diagrama');
    Route::middleware('can:gestionar-inspeccion-sag')->group(function (): void {
        Route::post('/', [DiagramaPalletsSagController::class, 'store']);
        Route::put('/{diagrama}', [DiagramaPalletsSagController::class, 'update'])->whereUuid('diagrama');
    });
});
