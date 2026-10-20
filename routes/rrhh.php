<?php

use App\Rrhh\Http\RrhhController as R;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('rrhh')->group(function (): void {
    Route::post('/marcajes', [R::class, 'marcar'])->middleware(['can:marcar-rrhh', 'throttle:rrhh-marcaje']);
    Route::middleware('can:consultar-rrhh')->group(function (): void {
        Route::get('/personas', [R::class, 'personas']);
        Route::get('/asignaciones', [R::class, 'asignaciones']);
        Route::get('/personas/{persona}/marcajes', [R::class, 'marcajes'])->whereUuid('persona');
        Route::get('/historial', [R::class, 'historial']);
        Route::get('/fuentes/{fuente}', [R::class, 'integracion'])->whereUuid('fuente');
    });
    Route::middleware(['can:gestionar-rrhh', 'throttle:rrhh-gestion'])->group(function (): void {
        Route::post('/personas', [R::class, 'guardarPersona']);
        Route::put('/personas/{persona}', [R::class, 'actualizarPersona'])->whereUuid('persona');
        Route::post('/asignaciones', [R::class, 'asignar']);
        Route::patch('/asignaciones/{asignacion}/estado', [R::class, 'estado'])->whereUuid('asignacion');
        Route::post('/marcajes/{marcaje}/corregir', [R::class, 'corregir'])->whereUuid('marcaje');
        Route::post('/fuentes', [R::class, 'guardarFuente']);
        Route::put('/fuentes/{fuente}', [R::class, 'configurarFuente'])->whereUuid('fuente');
        Route::post('/fuentes/{fuente}/vinculos', [R::class, 'vincular'])->whereUuid('fuente');
    });
    Route::post('/fuentes/{fuente}/importar', [R::class, 'importar'])->whereUuid('fuente')->middleware(['can:gestionar-rrhh', 'throttle:rrhh-importaciones']);
});
