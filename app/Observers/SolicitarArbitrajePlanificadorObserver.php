<?php

namespace App\Observers;

use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\ReservaBandaManiobra;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class SolicitarArbitrajePlanificadorObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ServicioEstadoArbitrajePlanificador $estado,
    ) {}

    /** @return array<int, class-string<Model>> */
    public static function modelosObservados(): array
    {
        return [
            Temporada::class,
            Camara::class,
            PlanOperacional::class,
            ManiobraOperacional::class,
            TareaMovimiento::class,
            ReservaBandaManiobra::class,
            CustodiaTemporalManiobra::class,
        ];
    }

    public function saved(Model $modelo): void
    {
        $this->solicitar($modelo);
    }

    public function deleted(Model $modelo): void
    {
        $this->solicitar($modelo);
    }

    public function restored(Model $modelo): void
    {
        $this->solicitar($modelo);
    }

    private function solicitar(Model $modelo): void
    {
        foreach ($this->temporadasAfectadas($modelo) as $temporadaId) {
            $this->estado->solicitar($temporadaId, class_basename($modelo));
        }
    }

    /** @return array<int, string> */
    private function temporadasAfectadas(Model $modelo): array
    {
        $temporadaId = match (true) {
            $modelo instanceof Temporada => $modelo->id,
            $modelo instanceof PlanOperacional => $modelo->temporada_id,
            $modelo instanceof ManiobraOperacional => $modelo->planOperacional()
                ->value('temporada_id'),
            $modelo instanceof TareaMovimiento => $modelo->planOperacional()
                ->value('temporada_id'),
            $modelo instanceof ReservaBandaManiobra,
            $modelo instanceof CustodiaTemporalManiobra => $modelo->maniobraOperacional()
                ->first()?->planOperacional()
                ->value('temporada_id'),
            default => null,
        };

        if (is_string($temporadaId)) {
            return [$temporadaId];
        }

        if ($modelo instanceof Camara) {
            $activa = app(ServicioTemporadaActiva::class)->buscar();

            return $activa ? [$activa->id] : [];
        }

        return [];
    }
}
