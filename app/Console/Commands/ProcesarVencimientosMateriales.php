<?php

namespace App\Console\Commands;

use App\Services\Materiales\ServicioProcesamientoVencimientosMaterial;
use Illuminate\Console\Command;

class ProcesarVencimientosMateriales extends Command
{
    protected $signature = 'materiales:procesar-vencimientos';

    protected $description = 'Bloquea material vencido y reasigna sus reservas vigentes por FEFO.';

    public function handle(ServicioProcesamientoVencimientosMaterial $servicio): int
    {
        $resumen = $servicio->procesar();
        $this->info(sprintf('Folios: %d; reservas liberadas: %d; reasignadas: %d; líneas insuficientes: %d.',
            $resumen['folios_procesados'], $resumen['reservas_liberadas'],
            $resumen['reservas_reasignadas'], count($resumen['lineas_insuficientes'])));
        foreach ($resumen['lineas_insuficientes'] as $linea) {
            $this->warn('Reserva insuficiente por vencimiento: '.json_encode($linea, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
