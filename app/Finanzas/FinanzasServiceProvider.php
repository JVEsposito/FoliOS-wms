<?php

namespace App\Finanzas;

use App\Finanzas\Console\Commands\CapturarHechosFinanzas;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Finanzas como módulo aparte. No registra rutas (están en routes/finanzas.php) ni
 * observa modelos operativos: lee la operación por su cuenta, en LectorOperacion.
 */
class FinanzasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->limites();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CapturarHechosFinanzas::class]);

        $reinicio = null;

        // Anunciar un comando no autoriza a borrar: todavía puede ser cancelado.
        Event::listen(CommandStarting::class, function (CommandStarting $evento) use (&$reinicio): void {
            if ($evento->command !== 'migrate:fresh') {
                return;
            }

            $conexion = $evento->input->getParameterOption('--database', null, true);
            if (($conexion ?? config('database.default')) === config('finanzas.conexion_operacion')) {
                $reinicio = $evento->input;
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $evento) use (&$reinicio): void {
            if ($evento->input === $reinicio) {
                $reinicio = null;
            }
        });

        // Si la base operativa se borró por otra vía (o en pruebas, donde Artisan no emite
        // CommandStarting), las tablas vacías de Finanzas se retiran antes de migrar.
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $evento) use (&$reinicio): void {
            if ($evento->method !== 'up' || ($evento->options['pretend'] ?? false)) {
                return;
            }

            $migrador = app('migrator');
            if (($migrador->getConnection() ?? config('database.default')) !== config('finanzas.conexion_operacion')) {
                return;
            }

            $corridas = $migrador->getRepository()->getRan();
            // Solo el migrate interno de un fresh confirmado, con el registro ya vacío.
            // Un db:wipe suelto conserva Finanzas y la guarda de huérfanas exige revisarla.
            if ($reinicio !== null && $corridas === []) {
                app(EsquemaFinanzas::class)->vaciar();
                $reinicio = null;
            }

            app(EsquemaFinanzas::class)->retirarHuerfanas($corridas);
        });
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
