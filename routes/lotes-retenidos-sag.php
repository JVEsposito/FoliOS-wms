<?php

use App\Http\Controllers\Api\RetencionLoteSagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:gestionar-lotes-retenidos-sag'])->prefix('sag/lotes-retenidos')->group(function (): void {
    Route::get('/catalogo', [RetencionLoteSagController::class, 'catalogo']);
    Route::get('/', [RetencionLoteSagController::class, 'index']);
    Route::post('/', [RetencionLoteSagController::class, 'store']);
    Route::get('/{retencion}', [RetencionLoteSagController::class, 'show'])->whereUuid('retencion');
    Route::put('/{retencion}', [RetencionLoteSagController::class, 'update'])->whereUuid('retencion');
    Route::get('/{retencion}/archivo/{formato}', [RetencionLoteSagController::class, 'archivo'])
        ->whereUuid('retencion')->whereIn('formato', ['pdf', 'xlsx']);
});
