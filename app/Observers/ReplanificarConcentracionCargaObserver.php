<?php

namespace App\Observers;

use App\Models\Carga;
use App\Models\PlanOperacional;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;

class ReplanificarConcentracionCargaObserver
{
    public function __construct(
        private readonly ServicioRecalculosPendientesPlanificador $recalculos,
    ) {}

    public function updated(Carga $carga): void
    {
        if (! $carga->wasChanged([
            'estado',
            'camara_objetivo_id',
            'version',
            'publicada_at',
            'cancelada_at',
            'cerrada_at',
        ])) {
            return;
        }

        $this->recalculos->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $carga->id);
        // El destino de un blocker puede servir a esta carga desde una labor ajena.
        $principales = PlanOperacional::query()
            ->where('referencia_tipo', 'carga_concentracion')
            ->where('referencia_id', '!=', $carga->id)
            ->whereHas('maniobras', fn ($consulta) => $consulta
                ->whereIn('estado', ['pendiente', 'en_ejecucion'])
                ->whereHas('objetivos', fn ($objetivos) => $objetivos
                    ->where('referencia_tipo', 'carga_concentracion')->where('referencia_id', $carga->id)))
            ->pluck('referencia_id');
        foreach ($principales as $cargaPrincipalId) {
            $this->recalculos->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $cargaPrincipalId);
        }
    }
}
