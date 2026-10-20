<?php

namespace App\Gestion;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Protege las bases de los módulos de gestión al migrar. Su registro de migraciones vive en la
 * base operativa: un migrate:fresh confirmado de esa base vacía también los módulos, y antes de
 * cada migración se retiran las tablas huérfanas vacías (con datos, la migración se detiene).
 */
class GestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModulosGestion::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $modulos = $this->app->make(ModulosGestion::class);
        /** @var array<class-string, object> $reinicios entrada del migrate:fresh confirmado, por módulo */
        $reinicios = [];

        // Anunciar un comando no autoriza a borrar: todavía puede ser cancelado.
        Event::listen(CommandStarting::class, function (CommandStarting $evento) use ($modulos, &$reinicios): void {
            if ($evento->command !== 'migrate:fresh') {
                return;
            }

            $conexion = $evento->input->getParameterOption('--database', null, true) ?? config('database.default');
            foreach ($modulos->esquemas() as $esquema) {
                if ($conexion === $esquema->conexionOperacion()) {
                    $reinicios[$esquema::class] = $evento->input;
                }
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $evento) use (&$reinicios): void {
            foreach ($reinicios as $clase => $entrada) {
                if ($evento->input === $entrada) {
                    unset($reinicios[$clase]);
                }
            }
        });

        // Si la base operativa se borró por otra vía (o en pruebas, donde Artisan no emite
        // CommandStarting), las tablas vacías de cada módulo se retiran antes de migrar.
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $evento) use ($modulos, &$reinicios): void {
            if ($evento->method !== 'up' || ($evento->options['pretend'] ?? false)) {
                return;
            }

            $migrador = app('migrator');
            $conexion = $migrador->getConnection() ?? config('database.default');
            $corridas = null;
            foreach ($modulos->esquemas() as $esquema) {
                if ($conexion !== $esquema->conexionOperacion()) {
                    continue;
                }

                $corridas ??= $migrador->getRepository()->getRan();
                // Solo el migrate interno de un fresh confirmado, con el registro ya vacío.
                // Un db:wipe suelto conserva el módulo y la guarda de huérfanas exige revisarlo.
                if (isset($reinicios[$esquema::class]) && $corridas === []) {
                    $esquema->vaciar();
                    unset($reinicios[$esquema::class]);
                }

                $esquema->retirarHuerfanas($corridas);
            }
        });
    }
}
