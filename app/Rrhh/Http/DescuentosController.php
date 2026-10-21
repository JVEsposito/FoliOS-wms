<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\MandatoDescuento;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use App\Rrhh\Nomina\DestinosNomina;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DescuentosController extends Controller
{
    public function mandatos(Request $r, DestinosNomina $g): JsonResponse
    {
        $d = $r->validate(['persona_id' => ['required', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $filas = MandatoDescuento::query()->where('persona_id', $d['persona_id'])->orderByDesc('desde')->orderBy('id')->paginate(25);
        $filas->through(fn ($m) => [...$m->toArray(), ...$g->saldo($m)]);

        return response()->json(['data' => $filas]);
    }

    public function guardar(Request $r, DestinosNomina $g): JsonResponse
    {
        $d = $r->validate(['persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')], 'concepto_id' => ['required', 'uuid', Rule::exists('rrhh.rh_conceptos_nomina', 'id')],
            'empleador_id' => ['required', 'uuid', Rule::exists('rrhh.rh_empleadores', 'id')],
            'institucion_id' => ['required', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')], 'referencia' => ['required', 'string', 'max:100'],
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'cuota' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'], 'deuda_total' => ['nullable', 'string', 'regex:/^\d{1,15}$/'], 'cuotas' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'base_porcentaje' => ['nullable', 'in:remuneracion_bruta,liquido_legal,base_imponible'], 'pago_directo_institucion' => ['required', 'boolean'],
            'excepcion_ccaf' => ['nullable', 'in:vivienda_emergencia,salud'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->mandato($d, $r->user())], 201);
    }

    public function terminar(Request $r, MandatoDescuento $mandato): JsonResponse
    {
        $d = $r->validate(['hasta_actual' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);
        app(EsquemaRrhh::class)->transaccion(function () use ($d, $mandato, $r): void {
            Persona::query()->lockForUpdate()->findOrFail($mandato->persona_id);
            $m = MandatoDescuento::query()->lockForUpdate()->findOrFail($mandato->id);
            if ($m->hasta !== $d['hasta_actual'] || $d['hasta'] < $m->desde || $d['hasta'] >= $m->hasta || MovimientoNomina::query()->where('mandato_id', $m->id)->where('estado', 'aprobado')->where('fecha', '>', $d['hasta'])->exists()) {
                throw ValidationException::withMessages(['hasta' => 'Recargue la vigencia; no puede acortarla antes del inicio o de cuotas aprobadas.']);
            }
            $m->update(['hasta' => $d['hasta']]);
            app(Auditoria::class)->registrar('mandato_descuento', $m->id, 'terminado', $d, $r->user());
        });

        return response()->json(['data' => $mandato->fresh()]);
    }

    public function institucion(Request $r): JsonResponse
    {
        $d = $r->validate(['institucion_id' => ['required', 'uuid'], 'empleador_id' => ['nullable', 'uuid'], 'mes' => ['required', 'date_format:Y-m'], 'page' => ['nullable', 'integer', 'min:1']]);
        $mes = CarbonImmutable::parse($d['mes'].'-01');
        $q = MovimientoNomina::query()->where('rh_movimientos_nomina.institucion_id', $d['institucion_id'])->when($d['empleador_id'] ?? null, fn ($q, $id) => $q->where('rh_movimientos_nomina.empleador_id', $id))->whereBetween('fecha', [$mes->toDateString(), $mes->endOfMonth()->toDateString()]);
        $totales = (clone $q)->where('estado', 'aprobado')->join('rh_conceptos_nomina as c', 'c.id', '=', 'rh_movimientos_nomina.concepto_id')->selectRaw('c.tipo, SUM(rh_movimientos_nomina.total) as total')->groupBy('c.tipo')->pluck('total', 'tipo')->map(fn ($v) => (string) $v)->all();

        return response()->json(['data' => $q->leftJoin('rh_personas as p', 'p.id', '=', 'rh_movimientos_nomina.persona_id')->select(['rh_movimientos_nomina.*', 'p.nombre as persona_nombre'])->orderBy('fecha')->orderBy('rh_movimientos_nomina.id')->paginate(50), 'totales_aprobados' => $totales]);
    }
}
