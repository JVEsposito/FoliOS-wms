<?php

namespace App\Console\Commands;

use App\Models\LoteInspeccionSag;
use App\Services\InspeccionSag\ServicioPreparacionFisicaSag;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use Illuminate\Console\Command;
use Throwable;

class PrepararTrasladosInspeccionSag extends Command
{
    protected $signature = 'sag:preparar-traslados';

    protected $description = 'Reevalúa capacidad y traslados pendientes para preparar las inspecciones SAG';

    public function handle(
        ServicioPreparacionFisicaSag $preparacion,
        ServicioDesplieguePlanificador $despliegue,
    ): int {
        if ($despliegue->idsCamarasDirigidas() === []) {
            return self::SUCCESS;
        }

        $fallidos = 0;
        $revisados = 0;
        $preparacion->lotesPendientes()
            ->with('creadoPor')
            ->chunkById(50, function ($lotes) use ($preparacion, &$fallidos, &$revisados): void {
                foreach ($lotes as $lote) {
                    /** @var LoteInspeccionSag $lote */
                    try {
                        $preparacion->sincronizar($lote, $lote->creadoPor);
                        $revisados++;
                    } catch (Throwable $error) {
                        $fallidos++;
                        report($error);
                        $this->components->error("{$lote->codigo}: {$error->getMessage()}");
                    }
                }
            });

        $this->components->info("Inspecciones revisadas: {$revisados}. Con error: {$fallidos}.");

        return $fallidos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
