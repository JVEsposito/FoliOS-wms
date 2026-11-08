<?php

namespace App\Jobs;

use App\Services\Validacion\CatalogoIncrementalValidacion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepararCatalogoIncremental implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public readonly string $temporada,
        public readonly int $version,
    ) {}

    public function handle(CatalogoIncrementalValidacion $catalogo): void
    {
        $catalogo->preparar($this->temporada, $this->version);
    }
}
