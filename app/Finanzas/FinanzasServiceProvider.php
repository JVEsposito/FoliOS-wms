<?php

namespace App\Finanzas;

use App\Finanzas\Console\Commands\CapturarHechosFinanzas;
use App\Gestion\ModulosGestion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Finanzas como módulo aparte. No registra rutas (están en routes/finanzas.php) ni
 * observa modelos operativos: lee la operación por su cuenta, en LectorOperacion. Su base la
 * protege la base común de módulos de gestión (App\Gestion).
 */
class FinanzasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ModulosGestion::class, fn (ModulosGestion $modulos) => $modulos->registrar(EsquemaFinanzas::class));
    }

    public function boot(): void
    {
        $this->limites();

        if ($this->app->runningInConsole()) {
            $this->commands([CapturarHechosFinanzas::class]);
        }
    }

    /**
     * Límites con nombre propio. Con «throttle:N,M», todas las rutas limitadas comparten el
     * contador de cada usuario: revisar diferencias varias veces agotaría el límite de cerrar.
     */
    private function limites(): void
    {
        $clave = fn (string $grupo, Request $request): string => "finanzas:{$grupo}:".($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('finanzas-cierres', fn (Request $request): Limit => Limit::perMinute(6)->by($clave('cierres', $request)));
        RateLimiter::for('finanzas-diferencias', fn (Request $request): Limit => Limit::perMinute(30)->by($clave('diferencias', $request)));
        RateLimiter::for('finanzas-decisiones', fn (Request $request): Limit => Limit::perMinute(30)->by($clave('decisiones', $request)));
        RateLimiter::for('finanzas-exportaciones', fn (Request $request): Limit => Limit::perMinute(12)->by($clave('exportaciones', $request)));
    }
}
