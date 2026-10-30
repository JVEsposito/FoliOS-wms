<?php

use App\Http\Controllers\Api\DespachoDirectoPlanificadorController;
use App\Http\Controllers\Api\DiscrepanciaManiobraController;
use App\Http\Controllers\Api\FronteraFisicaController;
use App\Http\Controllers\Api\IntervencionPlanificadorController;
use App\Http\Controllers\Api\PlanOperacionalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:operar-camaras-productos'])->group(function () {
    Route::post('/tareas-movimiento/{tareaMovimiento}/resolver-destino', [PlanOperacionalController::class, 'resolverDestinoServidor']);
    Route::get(
        '/frontera-fisica/snapshot',
        [FronteraFisicaController::class, 'snapshot'],
    );
    Route::post(
        '/frontera-fisica/materializar',
        [FronteraFisicaController::class, 'materializar'],
    );
    Route::post(
        '/frontera-fisica/sin-destino',
        [FronteraFisicaController::class, 'sinDestino'],
    );
    Route::get(
        '/planes-operacionales/{planOperacional}/snapshot',
        [PlanOperacionalController::class, 'snapshot'],
    );
    Route::post(
        '/planes-operacionales/{planOperacional}/frontera',
        [PlanOperacionalController::class, 'materializarFrontera'],
    );
    Route::post(
        '/tareas-movimiento/{tareaMovimiento}/iniciar',
        [PlanOperacionalController::class, 'iniciar'],
    );
    Route::post(
        '/tareas-movimiento/{tareaMovimiento}/completar-extraccion-temporal',
        [PlanOperacionalController::class, 'completarExtraccionTemporal'],
    );
    Route::post(
        '/tareas-movimiento/{tareaMovimiento}/no-coincide',
        [PlanOperacionalController::class, 'reportarDiscrepancia'],
    );
    Route::post(
        '/tareas-movimiento/{tareaMovimiento}/completar-prefrio-directo',
        [DespachoDirectoPlanificadorController::class, 'completarPrefrio'],
    );
});

Route::middleware(['auth:sanctum', 'can:supervisar-camaras-productos'])
    ->prefix('discrepancias-maniobra')
    ->group(function () {
        Route::get('/', [DiscrepanciaManiobraController::class, 'index']);
        Route::post(
            '/{discrepanciaManiobra}/resolver',
            [DiscrepanciaManiobraController::class, 'resolver'],
        );
    });

Route::middleware(['auth:sanctum', 'can:supervisar-camaras-productos'])
    ->prefix('intervenciones-planificador')
    ->group(function () {
        Route::post(
            '/maniobras/{maniobraOperacional}/pausar',
            [IntervencionPlanificadorController::class, 'pausar'],
        );
        Route::post(
            '/maniobras/{maniobraOperacional}/reanudar',
            [IntervencionPlanificadorController::class, 'reanudar'],
        );
        Route::patch(
            '/maniobras/{maniobraOperacional}/prioridad',
            [IntervencionPlanificadorController::class, 'repriorizar'],
        );
        Route::post(
            '/reservas/expirar-vencidas',
            [IntervencionPlanificadorController::class, 'expirarReservas'],
        );
    });
