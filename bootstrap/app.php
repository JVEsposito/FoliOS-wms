<?php

use App\Exceptions\AdvertenciasMovimientoPendientes;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\FoliosCargaInvalidos;
use App\Exceptions\OperacionNoAutorizada;
use App\Exceptions\RegistroFueraDeTemporadaActiva;
use App\Exceptions\ServicioSagNoDisponible;
use App\Exceptions\TemporadaConPendientesDeCierre;
use App\Http\Middleware\AsegurarTemporadaActivaDelRegistro;
use App\Http\Middleware\ExigirCambioPasswordTablet;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/access-administration.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/existencias.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/planner.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/finanzas.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/comex.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/rrhh.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', ExigirCambioPasswordTablet::class);
        // Solo se escribe sobre registros de la temporada activa. Debe correr
        // cuando los parámetros de la ruta ya son modelos.
        $middleware->appendToGroup('api', AsegurarTemporadaActivaDelRegistro::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, AsegurarTemporadaActivaDelRegistro::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (
            AdvertenciasMovimientoPendientes $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'confirmacion_requerida',
                'advertencias' => $exception->advertencias,
            ], 409);
        });

        $exceptions->render(function (
            ConflictoOperacion $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'conflicto_operacional',
            ], 409);
        });

        $exceptions->render(function (
            RegistroFueraDeTemporadaActiva $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'temporada_no_activa',
                'temporada_registro' => $exception->temporadaRegistro,
                'temporada_activa' => $exception->temporadaActiva,
            ], 409);
        });

        $exceptions->render(function (
            TemporadaConPendientesDeCierre $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'temporada_con_pendientes',
                'pendientes' => $exception->pendientes,
            ], 409);
        });

        $exceptions->render(function (
            OperacionNoAutorizada $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'operacion_no_autorizada',
            ], 403);
        });

        $exceptions->render(function (
            FoliosCargaInvalidos $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'folios_no_asignables',
                'errores' => $exception->errores,
            ], 422);
        });

        $exceptions->render(function (
            ServicioSagNoDisponible $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'servicio_sag_no_disponible',
            ], 503);
        });

        $exceptions->render(function (DomainException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'codigo' => 'regla_de_negocio',
            ], 422);
        });
    })->create();
