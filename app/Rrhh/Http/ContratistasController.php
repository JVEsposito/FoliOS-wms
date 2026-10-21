<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Contratistas\ControlContratistas;
use App\Rrhh\Contratistas\RetencionesContratista;
use App\Rrhh\Models\AntecedenteContratista;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\ExpedienteContratista;
use App\Rrhh\Models\Persona;
use App\Rrhh\Models\RetencionContratista;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ContratistasController extends Controller
{
    public function dotacion(Request $r): JsonResponse
    {
        $d = $r->validate(['empleador_id' => ['required', 'uuid'], 'mes' => ['required', 'date_format:Y-m'], 'buscar' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $inicio = $d['mes'].'-01';

        return response()->json(['data' => Persona::query()->join('rh_contratos as c', 'c.persona_id', '=', 'rh_personas.id')->where('c.empleador_id', $d['empleador_id'])->where('c.tipo', '!=', 'honorarios')->where('c.desde', '<=', CarbonImmutable::parse($inicio)->endOfMonth()->toDateString())->where('c.hasta', '>=', $inicio)
            ->when($d['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('rh_personas.nombre', 'like', '%'.$s.'%')->orWhere('rh_personas.codigo', 'like', '%'.$s.'%')))->distinct()->orderBy('rh_personas.codigo')->paginate(50, ['rh_personas.id', 'rh_personas.codigo', 'rh_personas.nombre'])]);
    }

    public function expedientes(Request $r): JsonResponse
    {
        $d = $r->validate(['empleador_id' => ['required', 'uuid'], 'mes' => ['nullable', 'date_format:Y-m'], 'page' => ['nullable', 'integer', 'min:1']]);
        $e = Empleador::query()->findOrFail($d['empleador_id']);

        return response()->json(['data' => ExpedienteContratista::query()->where('empleador_id', $e->id)->when($d['mes'] ?? null, fn ($q, $s) => $q->where('mes', $s))->orderByDesc('mes')->orderBy('faena')->paginate(25, ['id', 'empleador_id', 'faena', 'mes', 'estado', 'version', 'personas_count', 'referencia_contrato', 'observacion', 'revisado_por', 'revisado_at']), 'empleador' => $e->only(['id', 'razon_social', 'rut', 'tipo'])]);
    }

    public function crear(Request $r, ControlContratistas $g): JsonResponse
    {
        $d = $r->validate(['empleador_id' => ['required', 'uuid', Rule::exists('rrhh.rh_empleadores', 'id')], 'faena' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'], 'mes' => ['required', 'date_format:Y-m'], 'referencia_contrato' => ['required', 'string', 'max:200'], ...$this->reglasPersonal()]);

        return response()->json(['data' => $g->crear($d, $r->user())], 201);
    }

    public function detalle(ExpedienteContratista $expediente, ControlContratistas $g, RetencionesContratista $retenciones): JsonResponse
    {
        return response()->json(['data' => $expediente, 'faltantes' => $g->faltantes($expediente), 'saldo_retenido' => $retenciones->saldo($expediente->id)]);
    }

    public function personal(Request $r, ExpedienteContratista $expediente, ControlContratistas $g): JsonResponse
    {
        $d = $r->validate([...$this->reglasPersonal(), 'version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->cambiarPersonal($expediente, $d, $r->user())]);
    }

    public function antecedentes(ExpedienteContratista $expediente): JsonResponse
    {
        return response()->json(['data' => AntecedenteContratista::query()->where('expediente_id', $expediente->id)->orderByDesc('created_at')->orderBy('id')->paginate(25)]);
    }

    public function antecedente(Request $r, ExpedienteContratista $expediente, ControlContratistas $g): JsonResponse
    {
        $d = $r->validate(['version_expediente' => ['required', 'integer', 'min:1'], 'tipo' => ['required', Rule::in(ControlContratistas::TIPOS)], 'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'fecha_documento' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->timezone(config('app.operational_timezone'))->toDateString()], 'referencia' => ['required', 'string', 'min:5', 'max:500'], 'cubre_todos' => ['required', 'boolean'], 'personas' => ['nullable', 'array', 'max:1000'], 'personas.*' => ['uuid', 'distinct']]);

        return response()->json(['data' => $g->antecedente($expediente, $d, $r->user())], 201);
    }

    public function revisarAntecedente(Request $r, AntecedenteContratista $antecedente, ControlContratistas $g): JsonResponse
    {
        return response()->json(['data' => $g->revisarAntecedente($antecedente, $this->revision($r, true), $r->user())]);
    }

    public function revisar(Request $r, ExpedienteContratista $expediente, ControlContratistas $g): JsonResponse
    {
        return response()->json(['data' => $g->revisarExpediente($expediente, $this->revision($r), $r->user())]);
    }

    public function retenciones(ExpedienteContratista $expediente, RetencionesContratista $g): JsonResponse
    {
        return response()->json(['data' => RetencionContratista::query()->where('expediente_id', $expediente->id)->orderBy('fecha')->orderBy('id')->paginate(25), 'saldo_retenido' => $g->saldo($expediente->id)]);
    }

    public function retencion(Request $r, ExpedienteContratista $expediente, RetencionesContratista $g): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'tipo' => ['required', 'in:retencion,liberacion,pago_subrogacion'], 'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->timezone(config('app.operational_timezone'))->toDateString()],
            'monto' => ['required', 'string', 'regex:/^\d{1,15}$/'], 'referencia' => ['required', 'string', 'min:5', 'max:200'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000'],
            'institucion_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')], 'persona_destino_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_personas', 'id')]]);

        return response()->json(['data' => $g->registrar($expediente, $d, $r->user())], 201);
    }

    public function anularRetencion(Request $r, RetencionContratista $retencion, RetencionesContratista $g): JsonResponse
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->anular($retencion, $d['motivo'], $r->user())]);
    }

    public function historial(Request $r): JsonResponse
    {
        $d = $r->validate(['entidad' => ['required', 'in:expediente_contratista,antecedente_contratista,retencion_contratista'], 'registro_id' => ['required', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        unset($d['page']);

        return response()->json(['data' => EventoRrhh::query()->where($d)->orderByDesc('id')->paginate(50)]);
    }

    private function reglasPersonal(): array
    {
        return ['personal' => ['required', 'array', 'min:1', 'max:1000'], 'personal.*' => ['required', 'uuid', 'distinct']];
    }

    private function revision(Request $r, bool $antecedente = false): array
    {
        return $r->validate(['version' => ['required', 'integer', 'min:1'], 'estado' => ['required', Rule::in($antecedente ? ['pendiente', 'conforme', 'observado', 'descartado'] : ['pendiente', 'conforme', 'observado'])], 'observacion' => ['required', 'string', 'min:10', 'max:1000']]);
    }
}
