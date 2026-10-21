<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Models\Afiliacion;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\Institucion;
use App\Rrhh\Nomina\RegistroPrevisional;
use App\Rules\RutChileno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PrevisionController extends Controller
{
    public function directorio(Request $r, string $tipo): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100'], 'tipo_entidad' => ['nullable', 'string', 'max:30'], 'regimen' => ['nullable', Rule::in(array_keys(RegistroPrevisional::REGIMENES))], 'page' => ['nullable', 'integer', 'min:1']]);
        $clase = $tipo === 'institucion' ? Institucion::class : Empleador::class;

        return response()->json(['data' => $clase::query()->when($d['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('razon_social', 'like', '%'.$s.'%')->orWhere('rut', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')))->when($d['tipo_entidad'] ?? null, fn ($q, $s) => $q->where('tipo', $s))->when($tipo === 'institucion' && isset($d['regimen']), fn ($q) => $q->whereIn('tipo', RegistroPrevisional::REGIMENES[$d['regimen']]))->orderBy('razon_social')->orderBy('id')->paginate(50), 'tipos' => $tipo === 'institucion' ? RegistroPrevisional::INSTITUCIONES : RegistroPrevisional::EMPLEADORES]);
    }

    public function guardar(Request $r, string $tipo, RegistroPrevisional $g, ?string $id = null): JsonResponse
    {
        $d = $r->validate([...$this->vigencia(), 'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'],
            'rut' => ['required', 'string', 'max:15', 'regex:/^[0-9.\-Kk]+$/', new RutChileno], 'razon_social' => ['required', 'string', 'max:160'],
            'tipo' => ['required', Rule::in($tipo === 'institucion' ? RegistroPrevisional::INSTITUCIONES : RegistroPrevisional::EMPLEADORES)],
            'codigo_oficial' => ['nullable', 'string', 'max:40'], 'codigo_externo' => ['nullable', 'string', 'max:100'], 'activo' => ['required', 'boolean'],
            'verificado_en' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $g->directorio($tipo, $d, $id, $r->user())], $id ? 200 : 201);
    }

    public function contratos(Request $r): JsonResponse
    {
        $d = $r->validate(['persona_id' => ['required', 'uuid'], 'vigente_en' => ['nullable', 'date_format:Y-m-d'], 'buscar' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => ContratoLaboral::query()->where('persona_id', $d['persona_id'])->when($d['vigente_en'] ?? null, fn ($q, $f) => $q->where('desde', '<=', $f)->where('hasta', '>=', $f))->when($d['buscar'] ?? null, fn ($q, $s) => $q->where('referencia', 'like', '%'.$s.'%'))->orderByDesc('desde')->orderBy('id')->paginate(25)]);
    }

    public function contrato(Request $r, RegistroPrevisional $g): JsonResponse
    {
        $d = $r->validate([...$this->vigencia(), 'persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')], 'empleador_id' => ['required', 'uuid', Rule::exists('rrhh.rh_empleadores', 'id')],
            'tipo' => ['required', 'in:indefinido,plazo_fijo,obra_faena,honorarios'], 'referencia' => ['required', 'string', 'max:100'], 'prorroga_de' => ['nullable', 'uuid', Rule::exists('rrhh.rh_contratos', 'id')]]);

        return response()->json(['data' => $g->contrato($d, $r->user())], 201);
    }

    public function afiliaciones(Request $r): JsonResponse
    {
        $d = $r->validate(['persona_id' => ['required_without:empleador_id', 'prohibits:empleador_id', 'uuid'], 'empleador_id' => ['required_without:persona_id', 'prohibits:persona_id', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => Afiliacion::query()->where(isset($d['persona_id']) ? 'persona_id' : 'empleador_id', $d['persona_id'] ?? $d['empleador_id'])->orderByDesc('desde')->orderBy('id')->paginate(25)]);
    }

    public function afiliacion(Request $r, RegistroPrevisional $g): JsonResponse
    {
        $d = $r->validate([...$this->vigencia(), 'persona_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_personas', 'id')], 'empleador_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_empleadores', 'id')],
            'institucion_id' => ['required', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')], 'regimen' => ['required', Rule::in(array_keys(RegistroPrevisional::REGIMENES))],
            'referencia' => ['required', 'string', 'max:100'], 'moneda_plan' => ['nullable', 'in:CLP,UF'], 'precio_plan' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,6})?$/']]);

        return response()->json(['data' => $g->afiliacion($d, $r->user())], 201);
    }

    public function terminar(Request $r, string $tipo, string $id, RegistroPrevisional $g): JsonResponse
    {
        $d = $r->validate(['hasta_actual' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->terminar($tipo, $id, $d, $r->user())]);
    }

    public function historial(Request $r): JsonResponse
    {
        $d = $r->validate(['entidad' => ['required', 'in:institucion,empleador,contrato,afiliacion'], 'registro_id' => ['required', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        unset($d['page']);

        return response()->json(['data' => EventoRrhh::query()->where($d)->orderByDesc('id')->paginate(50)]);
    }

    private function vigencia(): array
    {
        return ['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
