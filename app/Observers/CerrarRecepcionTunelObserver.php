<?php

namespace App\Observers;

use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\PlanOperacional;
use App\Models\TareaMovimiento;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;

class CerrarRecepcionTunelObserver
{
    public function updated(TareaMovimiento $tarea): void
    {
        $completada = $tarea->wasChanged('estado') && $tarea->estado === EstadoTareaMovimiento::Completada;
        // Un retiro de REPA cumplido fuera del planificador (el camarero ubicó el pallet) también resuelve su objetivo.
        $cumplidaFuera = $tarea->wasChanged('contexto') && $this->cumplidaFueraPlanificador($tarea);
        if (! $completada && ! $cumplidaFuera) {
            return;
        }

        $planes = collect();
        $planPropio = $tarea->planOperacional()->first();
        if ($this->esRecepcionConCierreAutomatico($planPropio)) {
            $planes->push($planPropio);
        }

        $frontera = collect([$tarea->id]);
        $visitadas = [];
        while ($frontera->isNotEmpty()) {
            $ids = $frontera
                ->filter(fn (string $id): bool => ! in_array($id, $visitadas, true))
                ->values();
            if ($ids->isEmpty()) {
                break;
            }
            $visitadas = [...$visitadas, ...$ids->all()];

            $predecesoras = TareaMovimiento::query()
                ->whereIn('reemplazada_por_tarea_id', $ids)
                ->with('planOperacional')
                ->get();
            $predecesoras->each(function (TareaMovimiento $reemplazada) use ($planes): void {
                if ($this->esRecepcionConCierreAutomatico($reemplazada->planOperacional)) {
                    $planes->push($reemplazada->planOperacional);
                }
            });
            $frontera = $predecesoras->pluck('id');
        }

        $planes
            ->unique('id')
            ->each(fn (PlanOperacional $plan): mixed => $this->evaluarPlan($plan, $tarea));
    }

    private function evaluarPlan(PlanOperacional $plan, TareaMovimiento $disparadora): void
    {
        $plan = PlanOperacional::query()->lockForUpdate()->find($plan->id);
        if (! $plan
            || ! $this->esRecepcionConCierreAutomatico($plan)
            || $plan->estado->esFinal()) {
            return;
        }

        $tareas = TareaMovimiento::query()
            ->where('plan_operacional_id', $plan->id)
            ->orderBy('secuencia')
            ->lockForUpdate()
            ->get();

        if ($tareas->isEmpty()
            || $tareas->contains(fn (TareaMovimiento $tarea): bool => ! $this->estaResuelta($tarea))) {
            return;
        }

        $plan->update([
            'estado' => EstadoPlanOperacional::Completado,
            'completado_por_user_id' => $disparadora->responsable_user_id ?? $disparadora->cancelada_por_user_id,
            'completado_at' => $disparadora->completada_at ?? $disparadora->cancelada_at ?? now(),
            'version' => $plan->version + 1,
        ]);
    }

    private function esRecepcionConCierreAutomatico(?PlanOperacional $plan): bool
    {
        return $plan && in_array($plan->tipo, [
            TipoPlanOperacional::RecepcionTunel,
            TipoPlanOperacional::RecepcionRepaletizaje,
        ], true);
    }

    private function estaResuelta(TareaMovimiento $tarea): bool
    {
        $actual = $tarea;
        $visitadas = [];

        while (! in_array($actual->id, $visitadas, true)) {
            if ($actual->estado === EstadoTareaMovimiento::Completada || $this->cumplidaFueraPlanificador($actual)) {
                return true;
            }
            if ($actual->estado !== EstadoTareaMovimiento::Cancelada
                || ! $actual->reemplazada_por_tarea_id) {
                return false;
            }

            $visitadas[] = $actual->id;
            $actual = TareaMovimiento::query()->find($actual->reemplazada_por_tarea_id);
            if (! $actual) {
                return false;
            }
        }

        return false;
    }

    private function cumplidaFueraPlanificador(TareaMovimiento $tarea): bool
    {
        return $tarea->estado === EstadoTareaMovimiento::Cancelada
            && data_get($tarea->contexto, ServicioDepuracionIngresosCamara::CUMPLIDA_FUERA_PLANIFICADOR) === true;
    }
}
