<?php

namespace App\Services\Planificador;

use App\Enums\FuncionCamarero;
use App\Enums\TipoPlanOperacional;
use App\Models\LaborCamarero;
use App\Models\TareaMovimiento;
use Illuminate\Database\Eloquent\Builder;

class CatalogoLaborCamarero
{
    public function describir(TareaMovimiento $tarea): array
    {
        $plan = $tarea->planOperacional;
        $c = $tarea->contexto ?? [];
        $p = $plan->contexto ?? [];
        $proceso = $c['proceso_prefrio_id'] ?? ($plan->tipo === TipoPlanOperacional::RecepcionTunel ? $plan->referencia_id : null);
        if ($plan->tipo === TipoPlanOperacional::RecepcionTunel || ($c['origen_logico'] ?? '') === 'tunel_prefrio') {
            $funcion = FuncionCamarero::RecepcionTuneles;
            $tipo = 'tunel';
            $objeto = $proceso ?? $plan->id;
            $preferido = $c['tunel_prefrio_id'] ?? $p['tunel_prefrio_id'] ?? null;
        } elseif ($plan->tipo === TipoPlanOperacional::RecepcionRepaletizaje) {
            $funcion = FuncionCamarero::RecepcionRepa;
            $tipo = 'plan';
            $objeto = $plan->id;
            $preferido = null;
        } elseif (in_array($plan->tipo, [TipoPlanOperacional::ConcentracionCarga, TipoPlanOperacional::DespachoDirecto], true)) {
            $funcion = FuncionCamarero::SeparacionCargas;
            $tipo = 'carga';
            $objeto = $c['carga_id'] ?? $p['carga_id'] ?? $plan->referencia_id ?? $plan->id;
            $preferido = $objeto;
        } elseif ($plan->tipo === TipoPlanOperacional::PreparacionInspeccion) {
            $funcion = FuncionCamarero::PreparacionSag;
            $tipo = 'sag';
            $objeto = $plan->id;
            $preferido = $plan->id;
        } elseif ($plan->tipo === TipoPlanOperacional::AlmacenamientoPallet && ($c['origen_logico'] ?? $p['origen_logico'] ?? '') === 'recepcion_externa') {
            $funcion = FuncionCamarero::RecepcionPacking;
            $tipo = 'plan';
            $objeto = $plan->id;
            $preferido = null;
        } else {
            $funcion = FuncionCamarero::ApoyoCamaras;
            $tipo = 'plan';
            $objeto = $plan->id;
            $preferido = null;
        }

        return ['clave' => $plan->temporada_id.':'.$tipo.':'.$objeto, 'funcion' => $funcion->value,
            'titulo' => $plan->titulo, 'preferencia_id' => $preferido,
            'contexto' => ['temporada_id' => $plan->temporada_id, 'plan_id' => $plan->id, 'tipo' => $tipo,
                'objeto_id' => $objeto, 'proceso_prefrio_id' => $proceso]];
    }

    public function tareas(LaborCamarero $labor): Builder
    {
        $c = $labor->contexto;
        $tipo = $c['tipo'];
        $id = $c['objeto_id'];
        $q = TareaMovimiento::query()->whereHas('planOperacional', fn (Builder $p) => $p->where('temporada_id', $c['temporada_id']));
        if ($tipo === 'carga') {
            return $q->whereHas('planOperacional', fn (Builder $p) => $p->whereIn('tipo', [TipoPlanOperacional::ConcentracionCarga, TipoPlanOperacional::DespachoDirecto]))
                ->where(fn (Builder $t) => $t->where('contexto->carga_id', $id)
                    ->orWhereHas('planOperacional', fn (Builder $p) => $p->where('contexto->carga_id', $id))
                    ->orWhereHas('planOperacional', fn (Builder $p) => $p->where('referencia_tipo', 'carga_concentracion')->where('referencia_id', $id)))
                ->where(fn (Builder $t) => $t->whereNull('contexto->origen_logico')->orWhere('contexto->origen_logico', '!=', 'tunel_prefrio'));
        }
        if ($tipo === 'tunel' && $c['proceso_prefrio_id']) {
            return $q->where(fn (Builder $t) => $t->where('contexto->proceso_prefrio_id', $c['proceso_prefrio_id'])
                ->orWhereHas('planOperacional', fn (Builder $p) => $p->where('tipo', TipoPlanOperacional::RecepcionTunel)->where('referencia_id', $c['proceso_prefrio_id'])));
        }

        return $q->where('plan_operacional_id', $c['plan_id']);
    }

    public function afinidad(array $labor, ?array $asignacion, ?string $foco = null): int
    {
        if (! $asignacion) {
            return 0;
        }
        $funciones = [$asignacion['funcion_principal'], ...($asignacion['funciones_apoyo'] ?? [])];
        $indice = array_search($labor['funcion'], $funciones, true);
        $peso = $indice === false ? 0 : max(10, 300 - $indice * 60);
        if ($peso && ($asignacion['preferencia']['id'] ?? null) === $labor['preferencia_id']) {
            $peso += 20;
        }
        // La continuidad prevalece entre urgencias comparables; la prioridad física sigue arriba.
        if ($foco === $labor['clave']) {
            $peso += 500;
        }

        return $peso;
    }
}
