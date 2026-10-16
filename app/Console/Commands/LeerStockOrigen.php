<?php

namespace App\Console\Commands;

use App\Services\Integraciones\ServicioLecturaStockOrigen;
use Illuminate\Console\Command;

class LeerStockOrigen extends Command
{
    protected $signature = 'integraciones:leer-stock-origen';

    protected $description = 'Lee el stock de producto terminado del sistema de origen (solo lectura) para saber qué falta por validar.';

    public function handle(ServicioLecturaStockOrigen $servicio): int
    {
        $lectura = $servicio->leer();
        if ($lectura === null) {
            $this->line('Lectura del stock de origen sin configurar, deshabilitada o en curso.');

            return self::SUCCESS;
        }
        if ($lectura->estado !== 'correcta') {
            $this->warn("Lectura fallida ({$lectura->causa}): {$lectura->mensaje}");

            return self::FAILURE;
        }
        $this->info(sprintf(
            'Stock de origen: %d pallets (%d nuevos, %d modificados, %d retirados) desde %d filas.',
            $lectura->pallets, $lectura->nuevos, $lectura->modificados, $lectura->retirados, $lectura->filas,
        ));

        return self::SUCCESS;
    }
}
