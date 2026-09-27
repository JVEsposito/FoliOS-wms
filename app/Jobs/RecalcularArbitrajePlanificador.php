<?php

namespace App\Jobs;

use App\Services\Planificador\ServicioArbitrajeManiobras;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RecalcularArbitrajePlanificador implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 120;

    /** @var array<int, int> */
    public array $backoff = [2, 5, 15, 30];

    public function __construct(
        public readonly string $temporadaId,
    ) {}

    public function handle(
        ServicioArbitrajeManiobras $arbitraje,
        ServicioEstadoArbitrajePlanificador $estado,
    ): void {
        if (! in_array(config('planificador.mode'), ['shadow', 'guided'], true)) {
            return;
        }

        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada || $temporada->id !== $this->temporadaId) {
            return;
        }

        $version = $estado->iniciarCalculo($this->temporadaId);
        $inicio = hrtime(true);
        try {
            $ciclo = $arbitraje->arbitrar($temporada);
            $estado->completarCalculo(
                $this->temporadaId,
                $version,
                $ciclo,
                $this->duracionMs($inicio),
            );
        } catch (Throwable $error) {
            $estado->registrarFallo(
                $this->temporadaId,
                $error,
                $this->duracionMs($inicio),
            );

            throw $error;
        }

        if ($estado->requiereRecalculo($this->temporadaId)) {
            self::dispatch($this->temporadaId);
        }
    }

    public function uniqueId(): string
    {
        return "arbitraje-planificador:{$this->temporadaId}";
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    private function duracionMs(int $inicio): int
    {
        return max(0, (int) round((hrtime(true) - $inicio) / 1_000_000));
    }
}
