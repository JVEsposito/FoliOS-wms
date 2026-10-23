<?php

namespace App\Jobs;

use App\Services\Integraciones\ServicioOperacionesIntegracion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EjecutarOperacionIntegracion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operacionId)
    {
        $this->onConnection('integraciones');
        $this->onQueue('integraciones');
    }

    public function handle(ServicioOperacionesIntegracion $servicio): void
    {
        $servicio->ejecutar($this->operacionId);
    }

    public function failed(?Throwable $error): void
    {
        // Nunca se incorpora el texto PDO al historial de la aplicación.
        app(ServicioOperacionesIntegracion::class)->interrumpir($this->operacionId);
    }
}
