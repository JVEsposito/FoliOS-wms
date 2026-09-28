<?php

namespace App\Console\Commands;

use App\Services\Planificador\ServicioSaludProcesos;
use Illuminate\Console\Command;

final class LatidoScheduler extends Command
{
    protected $signature = 'salud:latido-scheduler';

    protected $description = 'Registra el último ciclo del programador de tareas';

    public function handle(ServicioSaludProcesos $procesos): int
    {
        $procesos->marcarScheduler();

        return self::SUCCESS;
    }
}
