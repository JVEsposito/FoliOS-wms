<?php

namespace App\Finanzas;

use App\Finanzas\Console\Commands\CapturarHechosFinanzas;
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

        // El registro de migraciones vive en la base operativa y también anota las de
        // Finanzas: migrate:fresh y db:wipe borran las tablas de Finanzas junto con él.
        Event::listen(CommandStarting::class, function (CommandStarting $evento): void {
            if (! in_array($evento->command, ['migrate:fresh', 'db:wipe'], true)) {
                return;
            }

            $conexion = $evento->input->getParameterOption('--database', null, true);
            if ($conexion === null || $conexion === config('database.default')) {
                app(EsquemaFinanzas::class)->vaciar();
            }
        });

        // Si la base operativa se borró por otra vía (o en pruebas, donde Artisan no emite
        // CommandStarting), las tablas vacías de Finanzas se retiran antes de migrar.
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $evento): void {
            if ($evento->method !== 'up' || ($evento->options['pretend'] ?? false)) {
                return;
            }

            app(EsquemaFinanzas::class)->retirarHuerfanas(app('migrator')->getRepository()->getRan());
        });
    }
}
