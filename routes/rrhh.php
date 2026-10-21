<?php

use App\Rrhh\Http\DescuentosController as D;
use App\Rrhh\Http\IntegracionController;
use App\Rrhh\Http\NominaController as N;
use App\Rrhh\Http\PrevisionController as P;
use App\Rrhh\Http\RrhhController as R;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:consultar-rrhh', 'can:consultar-remuneraciones-rrhh'])->prefix('rrhh/nomina')->group(function (): void {
    Route::get('/mandatos', [D::class, 'mandatos']);
    Route::get('/por-institucion', [D::class, 'institucion']);
    Route::middleware(['can:gestionar-remuneraciones-rrhh', 'throttle:rrhh-gestion'])->group(function (): void {
        Route::post('/mandatos', [D::class, 'guardar']);
        Route::patch('/mandatos/{mandato}', [D::class, 'terminar'])->whereUuid('mandato');
    });
});

Route::middleware(['auth:sanctum', 'can:consultar-rrhh', 'can:consultar-remuneraciones-rrhh'])->prefix('rrhh/prevision')->group(function (): void {
    Route::get('/directorio/{tipo}', [P::class, 'directorio'])->whereIn('tipo', ['institucion', 'empleador']);
    Route::get('/contratos', [P::class, 'contratos']);
    Route::get('/afiliaciones', [P::class, 'afiliaciones']);
    Route::get('/historial', [P::class, 'historial']);
    Route::middleware(['can:gestionar-remuneraciones-rrhh', 'throttle:rrhh-gestion'])->group(function (): void {
        Route::post('/directorio/{tipo}', [P::class, 'guardar'])->whereIn('tipo', ['institucion', 'empleador']);
        Route::put('/directorio/{tipo}/{id}', [P::class, 'guardar'])->whereIn('tipo', ['institucion', 'empleador'])->whereUuid('id');
        Route::post('/contratos', [P::class, 'contrato']);
        Route::post('/afiliaciones', [P::class, 'afiliacion']);
        Route::patch('/vigencias/{tipo}/{id}', [P::class, 'terminar'])->whereIn('tipo', ['contrato', 'afiliacion'])->whereUuid('id');
    });
});

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

Route::middleware('auth:sanctum')->prefix('rrhh')->group(function (): void {
    Route::get('/cargos', [N::class, 'cargos'])->middleware('can:consultar-rrhh');
    Route::middleware(['can:gestionar-rrhh', 'throttle:rrhh-gestion'])->group(function (): void {
        Route::post('/cargos', [N::class, 'cargo']);
        Route::put('/cargos/{cargo}', [N::class, 'editarCargo'])->whereUuid('cargo');
    });
    Route::middleware(['can:consultar-remuneraciones-rrhh', 'can:consultar-rrhh'])->prefix('nomina')->group(function (): void {
        Route::get('/conceptos', [N::class, 'catalogo']);
        Route::get('/personas/{persona}', [N::class, 'ficha'])->whereUuid('persona');
        Route::get('/movimientos', [N::class, 'movimientos']);
        Route::get('/historial', [N::class, 'historial']);
        Route::middleware(['can:gestionar-remuneraciones-rrhh', 'throttle:rrhh-gestion'])->group(function (): void {
            Route::post('/conceptos', [N::class, 'concepto']);
            Route::patch('/conceptos/{concepto}', [N::class, 'activarConcepto'])->whereUuid('concepto');
            Route::patch('/vigencias/{tipo}/{id}', [N::class, 'terminar'])->whereIn('tipo', ['condicion', 'pacto_concepto'])->whereUuid('id');
            Route::post('/condiciones', [N::class, 'condicion']);
            Route::post('/asignaciones', [N::class, 'pacto']);
            Route::post('/parametros', [N::class, 'parametro']);
            Route::post('/simular', [N::class, 'simular']);
            Route::post('/movimientos', [N::class, 'crear']);
            Route::patch('/movimientos/{movimiento}', [N::class, 'estado'])->whereUuid('movimiento');
        });
    });
});

Route::middleware('auth:sanctum')->prefix('rrhh')->group(function (): void {
    Route::middleware(['can:gestionar-rrhh', 'throttle:rrhh-importaciones'])->group(function (): void {
        Route::post('/fuentes/{fuente}/consultar-geovictoria', [IntegracionController::class, 'consultar'])->whereUuid('fuente');
        Route::get('/fuentes/{fuente}/lotes', [IntegracionController::class, 'lotes'])->whereUuid('fuente');
        Route::post('/lotes/{lote}/reintentar', [IntegracionController::class, 'reintentar'])->whereUuid('lote');
    });
    Route::middleware(['can:consultar-rrhh', 'can:consultar-remuneraciones-rrhh'])->group(function (): void {
        Route::get('/nomina/entregas', [IntegracionController::class, 'entregas']);
        Route::post('/nomina/entregas/{entrega}/acuse', [IntegracionController::class, 'acuse'])->whereUuid('entrega')->middleware(['can:gestionar-remuneraciones-rrhh', 'throttle:rrhh-gestion']);
    });
});
