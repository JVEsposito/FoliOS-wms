<?php

namespace App\Finanzas;

use App\Finanzas\Console\Commands\CapturarHechosFinanzas;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Finanzas como módulo aparte. No registra rutas (están en routes/finanzas.php) ni
 * observa modelos operativos: lee la operación por su cuenta, en LectorOperacion.
 */
class FinanzasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
}
