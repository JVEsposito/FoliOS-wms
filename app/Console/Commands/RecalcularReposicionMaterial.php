<?php

namespace App\Console\Commands;

use App\Services\Materiales\ServicioReposicionMaterial;
use Illuminate\Console\Command;

class RecalcularReposicionMaterial extends Command
{
    protected $signature = 'materiales:recalcular-reposicion';

    protected $description = 'Recalcula niveles y notifica transiciones de reposición de la temporada activa.';

    public function handle(ServicioReposicionMaterial $servicio): int
    {
        $this->info('Ítems con cambio de estado: '.$servicio->recalcularTodos());

        return self::SUCCESS;
    }
}
