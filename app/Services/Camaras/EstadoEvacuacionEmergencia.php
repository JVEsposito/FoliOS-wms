<?php

namespace App\Services\Camaras;

use App\Enums\EstadoPlanOperacional;
use App\Enums\TipoPlanOperacional;
use App\Models\PlanOperacional;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class EstadoEvacuacionEmergencia
{
    /** @return Collection<int, PlanOperacional> */
    public function activas(): Collection
    {
        return $this->consulta()
            ->orderByDesc('created_at')
            ->get();
    }

    public function activa(string $camaraId): ?PlanOperacional
    {
        return $this->consulta()->where('referencia_id', $camaraId)
            ->orderByDesc('created_at')->first();
    }

    /** @return Builder<PlanOperacional> */
    private function consulta(): Builder
    {
        return PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::EvacuacionEmergencia->value)
            ->where('referencia_tipo', InterbloqueoEvacuacionEmergencia::REFERENCIA)
            ->whereNotIn('estado', [
                EstadoPlanOperacional::Completado->value,
                EstadoPlanOperacional::Cancelado->value,
            ])
            ->with('creadoPor:id,name');
    }

    /** @return array<string, mixed> */
    public function resumir(PlanOperacional $plan): array
    {
        $contexto = $plan->contexto ?? [];

        return [
            'id' => $plan->id,
            'version' => $plan->version,
            'motivo' => $plan->motivo,
            'declarada_por' => $plan->creadoPor?->name,
            'declarada_at' => $contexto['declarado_at'] ?? $plan->programado_at?->toAtomString(),
            'estado' => $contexto['estado_emergencia'] ?? 'declarada',
            'pallets_objetivo' => (int) ($contexto['pallets_objetivo'] ?? 0),
            'pallets_restantes' => (int) ($contexto['pallets_restantes'] ?? 0),
            'pallets_evacuados' => (int) ($contexto['pallets_evacuados'] ?? 0),
            'porcentaje_actual' => (float) ($contexto['porcentaje_actual'] ?? 0),
        ];
    }
}
