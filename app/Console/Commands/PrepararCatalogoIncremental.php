<?php

namespace App\Console\Commands;

use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\CatalogoIncrementalValidacion;
use Illuminate\Console\Command;

class PrepararCatalogoIncremental extends Command
{
    protected $signature = 'validacion:preparar-incremental';

    protected $description = 'Prepara la base local de cambios del catálogo PT activo';

    public function handle(ServicioTemporadaActiva $temporadas, CatalogoIncrementalValidacion $catalogo): int
    {
        $temporada = $temporadas->obtener();
        if (! $catalogo->preparar($temporada->id, $temporada->version_catalogo)) {
            $this->error('La versión cambió o hay una preparación en curso. Vuelve a intentarlo.');

            return self::FAILURE;
        }

        $this->info("Base incremental lista para {$temporada->codigo} v{$temporada->version_catalogo}.");

        return self::SUCCESS;
    }
}
