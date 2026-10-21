<?php

namespace App\Comex;

use App\Gestion\ModulosGestion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Comex como módulo aparte. Sus rutas están en routes/comex.php y lee la operación por su
 * cuenta, en LectorOperacion: la operación no sabe que Comex existe. Su base la protege la
 * base común de módulos de gestión (App\Gestion).
 */
class ComexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ModulosGestion::class, fn (ModulosGestion $modulos) => $modulos->registrar(EsquemaComex::class));
    }

    public function boot(): void
    {
        $clave = fn (string $grupo, Request $request): string => "comex:{$grupo}:".($request->user()?->getAuthIdentifier() ?? $request->ip());

        // Límites con nombre propio: con «throttle:N,M» todas las rutas comparten un contador.
        RateLimiter::for('comex-documentos', fn (Request $request): Limit => Limit::perMinute(60)->by($clave('documentos', $request)));
        RateLimiter::for('comex-archivos', fn (Request $request): Limit => Limit::perMinute(60)->by($clave('archivos', $request)));
        RateLimiter::for('comex-exportaciones', fn (Request $request): Limit => Limit::perMinute(20)->by($clave('exportaciones', $request)));
        RateLimiter::for('comex-configuracion', fn (Request $request): Limit => Limit::perMinute(30)->by($clave('configuracion', $request)));
        RateLimiter::for('comex-comercial', fn (Request $request): Limit => Limit::perMinute(60)->by($clave('comercial', $request)));
    }
}
