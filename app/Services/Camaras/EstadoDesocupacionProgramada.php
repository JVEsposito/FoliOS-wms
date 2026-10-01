<?php

namespace App\Services\Camaras;

use App\Enums\EstadoPlanOperacional;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\PlanOperacional;
use App\Services\Planificador\ServicioDesplieguePlanificador;

final class EstadoDesocupacionProgramada
{
    /** Lectura del último ciclo, incluido su cierre, sin recalcular ni crear tareas. */
    public function ultima(string $camaraId): ?PlanOperacional
    {
        return PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::DesocupacionCamara->value)
            ->where('referencia_tipo', ServicioDesocupacionProgramada::REFERENCIA)
            ->where('referencia_id', $camaraId)
            ->orderByDesc('ciclo_referencia')
            ->first();
    }

    public function habilitada(Camara $camara): bool
    {
        return app(ServicioDesplieguePlanificador::class)->dirige([$camara]);
    }

    /** @return array<string, mixed> */
    public function resumir(PlanOperacional $plan): array
    {
        $contexto = $plan->contexto ?? [];

        return [
            'id' => $plan->id,
            'version' => $plan->version,
            'activa' => ! in_array($plan->estado, [EstadoPlanOperacional::Completado, EstadoPlanOperacional::Cancelado], true),
            'estado' => $contexto['estado_desocupacion'] ?? $plan->estado->value,
            'motivo' => $plan->motivo,
            'motivo_pendiente' => $contexto['motivo_pendiente'] ?? null,
            'pallets_objetivo' => (int) ($contexto['total_inicial'] ?? 0),
            'pallets_restantes' => (int) ($contexto['pallets_restantes'] ?? 0),
            'pallets_evacuados' => (int) ($contexto['pallets_evacuados'] ?? 0),
            'porcentaje_actual' => (float) ($contexto['porcentaje_actual'] ?? 0),
            'lista_para_apagar' => (bool) ($contexto['lista_para_apagar'] ?? false),
        ];
    }
}
