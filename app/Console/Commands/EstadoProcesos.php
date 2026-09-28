<?php

namespace App\Console\Commands;

use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Planificador\ServicioSaludProcesos;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Console\Command;

final class EstadoProcesos extends Command
{
    protected $signature = 'sistema:estado-procesos';

    protected $description = 'Muestra latidos del worker y scheduler y la vigencia del arbitraje';

    public function handle(
        ServicioSaludProcesos $procesos,
        ServicioTemporadaActiva $temporadas,
        ServicioEstadoArbitrajePlanificador $arbitraje,
    ): int {
        foreach ($procesos->consultar() as $nombre => $estado) {
            $edad = $estado['antiguedad_segundos'] === null
                ? 'sin latido registrado'
                : "hace {$estado['antiguedad_segundos']} s (último: {$estado['ultimo_latido_at']})";
            $this->line("{$nombre}: {$estado['estado']} · {$edad}");
        }

        $temporada = $temporadas->buscar();
        $vigencia = $temporada ? $arbitraje->consultar($temporada) : null;
        $this->line('arbitraje: '.($vigencia['estado'] ?? 'sin temporada activa').' · '.($vigencia['detalle'] ?? 'No hay temporada activa.'));

        return self::SUCCESS;
    }
}
