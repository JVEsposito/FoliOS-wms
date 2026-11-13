<?php

namespace App\Services\Camaras;

use App\Enums\PrioridadCamara;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\TareaMovimiento;

/** Preferencia global; nunca convierte una posición insegura en un destino válido. */
final class ServicioPrioridadCamara
{
    public function finalidad(?TareaMovimiento $tarea = null, ?Folio $folio = null): PrioridadCamara
    {
        if ($tarea?->planOperacional?->tipo === TipoPlanOperacional::RecepcionTunel) {
            return PrioridadCamara::SalidaTuneles;
        }
        if (in_array($tarea?->planOperacional?->tipo, [TipoPlanOperacional::ConcentracionCarga, TipoPlanOperacional::DespachoDirecto], true)
            || ($folio ?? $tarea?->folio)?->asignacionCargaActual) {
            return PrioridadCamara::Despacho;
        }

        return PrioridadCamara::Almacenamiento;
    }

    public function orden(Camara $camara, PrioridadCamara $finalidad): int
    {
        $prioridad = $camara->prioridad_operacional ?? PrioridadCamara::General;

        return $prioridad === $finalidad ? 0 : ($prioridad === PrioridadCamara::General ? 1 : 2);
    }

    public function motivo(Camara $camara, PrioridadCamara $finalidad): string
    {
        return $this->orden($camara, $finalidad) === 0
            ? 'Prioridad de cámara: '.str_replace('_', ' ', $finalidad->value).'. ' : '';
    }
}
