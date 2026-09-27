<?php

namespace App\Console\Commands;

use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Console\Command;

class RecalcularArbitrajePlanificador extends Command
{
    protected $signature = 'planificador:recalcular-arbitraje {--forzar : Solicita un ciclo aunque la proyección esté vigente}';

    protected $description = 'Solicita el recálculo desacoplado del arbitraje para la temporada activa';

    public function handle(ServicioEstadoArbitrajePlanificador $estado): int
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            $this->components->info('No existe una temporada activa.');

            return self::SUCCESS;
        }

        $solicitado = $estado->asegurarProgramado(
            $temporada,
            (bool) $this->option('forzar'),
        );
        $this->components->info($solicitado
            ? 'Recálculo de arbitraje solicitado.'
            : 'La proyección de arbitraje continúa vigente.');

        return self::SUCCESS;
    }
}
