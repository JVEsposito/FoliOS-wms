<?php

namespace App\Providers;

use App\Http\Controllers\Api\RepaletizajeController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RepaletizajeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function (): void {
            Route::view(
                '/oficina/validacion/repaletizajes',
                'office.repalletizing',
            )->name('office.repaletizajes');
        });

        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api/validacion/repaletizajes')
            ->group(function (): void {
                Route::middleware('can:consultar-repaletizajes')->group(function (): void {
                    Route::get('/', [RepaletizajeController::class, 'index']);
                    Route::get('/registro/rrpl-01/planillas', [RepaletizajeController::class, 'planillasRegistro']);
                    Route::get('/registro/rrpl-01/en-blanco', [RepaletizajeController::class, 'registroEnBlanco']);
                    Route::get('/registro/rrpl-01', [RepaletizajeController::class, 'registro']);
                    Route::get(
                        '/folios/{numeroFolio}',
                        [RepaletizajeController::class, 'buscarFolio'],
                    );
                    Route::get('/{repaletizaje}/registro-reembalaje', [RepaletizajeController::class, 'registroReembalaje']);
                    Route::get(
                        '/{repaletizaje}',
                        [RepaletizajeController::class, 'show'],
                    );
                });

                Route::post('/', [RepaletizajeController::class, 'store'])
                    ->middleware('can:registrar-repaletizajes');
                Route::post(
                    '/{repaletizaje}/anular',
                    [RepaletizajeController::class, 'anular'],
                )->middleware('can:anular-repaletizajes');
            });
    }
}
