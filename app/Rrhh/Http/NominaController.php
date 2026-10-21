<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\CargoLaboral;
use App\Rrhh\Models\ConceptoNomina;
use App\Rrhh\Models\CondicionLaboral;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\PactoConcepto;
use App\Rrhh\Models\Persona;
use App\Rrhh\Nomina\CalculadorNovedad;
use App\Rrhh\Nomina\ConfiguracionNomina;
use App\Rrhh\Nomina\GestorNovedades;
use App\Rrhh\Nomina\ReglasChile;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NominaController extends Controller
{
    public function cargos(Request $r): JsonResponse
    {
        $f = $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => CargoLaboral::query()->when($f['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('nombre', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')))->orderBy('nombre')->paginate(50)]);
    }

    public function cargo(Request $r, ConfiguracionNomina $g): JsonResponse
    {
        return response()->json(['data' => $g->cargo($this->datosCargo($r), null, $r->user())], 201);
    }

    public function editarCargo(Request $r, CargoLaboral $cargo, ConfiguracionNomina $g): JsonResponse
    {
        return response()->json(['data' => $g->cargo($this->datosCargo($r, true), $cargo, $r->user())]);
    }

    private function datosCargo(Request $r, bool $editar = false): array
    {
        return $r->validate(['codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'], 'nombre' => ['required', 'string', 'max:120'], 'descripcion' => ['nullable', 'string', 'max:500'], 'activo' => ['required', 'boolean'], 'version' => [$editar ? 'required' : 'nullable', 'integer', 'min:1']]);
    }

    public function catalogo(Request $r): JsonResponse
    {
        $f = $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => ConceptoNomina::query()->when($f['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('nombre', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')))->orderBy('nombre')->paginate(50), 'parametros' => array_reverse(ReglasChile::parametros()), 'regla' => ReglasChile::VERSION, 'permisos' => ['gestionar' => $r->user()->can('gestionar-remuneraciones-rrhh')]]);
    }

    public function concepto(Request $r, ConfiguracionNomina $g): JsonResponse
    {
        $d = $r->validate(['codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'], 'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'in:haber,descuento,aporte_empleador'], 'calculo' => ['required', 'in:monto,porcentaje,horas_extra'],
            'remuneracion' => ['required', 'boolean'], 'imponible' => ['required', 'boolean'], 'tributable' => ['required', 'boolean'], 'base_he' => ['required', 'boolean'],
            'grupo_descuento' => ['nullable', 'in:voluntario,vivienda,cooperativa,obligatorio,judicial,ccaf,sindical,apv'], 'codigo_lre' => ['nullable', 'string', 'max:40'],
            'institucion_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')],
            'codigo_externo' => ['nullable', 'string', 'max:100'], 'fundamento' => ['required', 'string', 'min:10', 'max:1000'], 'activo' => ['required', 'boolean']]);

        return response()->json(['data' => $g->concepto($d, $r->user())], 201);
    }

    public function activarConcepto(Request $r, ConceptoNomina $concepto, EsquemaRrhh $e, Auditoria $a): JsonResponse
    {
        $d = $r->validate(['activo' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $e->transaccion(function () use ($d, $concepto, $a, $r): void {
            $c = ConceptoNomina::query()->lockForUpdate()->findOrFail($concepto->id);
            if ($c->version !== (int) $d['version']) {
                throw ValidationException::withMessages(['version' => 'El concepto cambió. Recargue.']);
            }
            $c->update(['activo' => $d['activo'], 'version' => $c->version + 1]);
            $a->registrar('concepto_nomina', $c->id, 'estado', $d, $r->user());
        });

        return response()->json(['data' => $concepto->fresh()]);
    }

    public function ficha(Request $r, Persona $persona): JsonResponse
    {
        return response()->json(['data' => ['persona' => ['id' => $persona->id, 'codigo' => $persona->codigo, 'nombre' => $persona->nombre],
            'condiciones' => CondicionLaboral::query()->where('persona_id', $persona->id)->orderByDesc('desde')->paginate(25, ['*'], 'condiciones_page'),
            'pactos' => PactoConcepto::query()->where('persona_id', $persona->id)->orderByDesc('desde')->paginate(25, ['*'], 'pactos_page')]]);
    }

    public function condicion(Request $r, ConfiguracionNomina $g): JsonResponse
    {
        $d = $r->validate([...$this->vigencia(), 'persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')],
            'cargo_id' => ['required', 'uuid', Rule::exists('rrhh.rh_cargos', 'id')], 'regimen' => ['required', 'in:general,especial,excluido'],
            'horas_semanales' => ['required', 'string', 'regex:/^(?:[1-9]|[1-5][0-9]|60)(?:\.\d{1,2})?$/'],
            'sueldo_mensual' => $this->decimal(), 'tramo_imm' => ['required', 'in:general,menor_mayor'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->condicion($d, $r->user())], 201);
    }

    public function pacto(Request $r, ConfiguracionNomina $g): JsonResponse
    {
        $d = $r->validate([...$this->vigencia(), 'persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')],
            'concepto_id' => ['required', 'uuid', Rule::exists('rrhh.rh_conceptos_nomina', 'id')], 'cantidad' => $this->decimal(), 'valor' => $this->decimal(), 'respaldo' => ['required', 'string', 'min:10', 'max:1000']]);
        foreach (['cantidad', 'valor'] as $campo) {
            $d[$campo] = ReglasChile::texto(ReglasChile::decimal($d[$campo]));
        }

        return response()->json(['data' => $g->pacto($d, $r->user())], 201);
    }

    public function terminar(Request $r, string $tipo, string $id, ConfiguracionNomina $g): JsonResponse
    {
        $d = $r->validate(['hasta_actual' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->terminar($tipo, $id, $d, $r->user())]);
    }

    public function parametro(Request $r, ConfiguracionNomina $g): JsonResponse
    {
        $d = $r->validate([...$this->vigencia(), 'imm_general' => ['required', 'integer', 'min:1', 'max:999999999'], 'imm_menor_mayor' => ['required', 'integer', 'min:1', 'max:999999999'], 'imm_no_remuneracional' => ['nullable', 'integer', 'min:1', 'max:999999999'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->parametro($d, $r->user())], 201);
    }

    public function simular(Request $r, CalculadorNovedad $g): JsonResponse
    {
        return response()->json(['data' => $g->calcular($this->datosMovimiento($r, false))]);
    }

    public function crear(Request $r, GestorNovedades $g): JsonResponse
    {
        return response()->json(['data' => $g->crear($this->datosMovimiento($r, true), $r->user())], 201);
    }

    public function estado(Request $r, MovimientoNomina $movimiento, GestorNovedades $g): JsonResponse
    {
        $d = $r->validate(['estado' => ['required', 'in:aprobado,anulado'], 'motivo' => ['required', 'string', 'min:10', 'max:1000'], 'acepta_incidencias' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $g->estado($movimiento, $d, $r->user())]);
    }

    public function movimientos(Request $r): JsonResponse
    {
        $d = $r->validate(['mes' => ['required', 'date_format:Y-m'], 'persona_id' => ['required', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $mes = CarbonImmutable::parse($d['mes'].'-01');

        return response()->json(['data' => MovimientoNomina::query()->where('persona_id', $d['persona_id'])->whereBetween('fecha', [$mes->toDateString(), $mes->endOfMonth()->toDateString()])->orderBy('fecha')->orderBy('id')->paginate(50)]);
    }

    public function historial(Request $r): JsonResponse
    {
        $d = $r->validate(['entidad' => ['required', 'in:concepto_nomina,condicion,pacto_concepto,parametro_legal,movimiento_nomina,mandato_descuento'], 'registro_id' => ['required', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        unset($d['page']);

        return response()->json(['data' => EventoRrhh::query()->where($d)->orderByDesc('id')->paginate(50)]);
    }

    private function datosMovimiento(Request $r, bool $crear): array
    {
        $d = $r->validate(['operacion_id' => [$crear ? 'required' : 'nullable', 'uuid'], 'persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')],
            'concepto_id' => ['required', 'uuid', Rule::exists('rrhh.rh_conceptos_nomina', 'id')], 'pacto_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_pactos_conceptos', 'id')],
            'institucion_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')], 'mandato_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_mandatos_descuento', 'id')],
            'contrato_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_contratos', 'id')],
            'liquido_legal' => $this->decimal(false), 'base_imponible' => $this->decimal(false), 'excepcion_ccaf' => ['nullable', 'in:vivienda_emergencia,salud'],
            'fecha' => ['required', 'date_format:Y-m-d'], 'cantidad' => $this->decimal(false), 'valor' => $this->decimal(false),
            'minutos' => ['nullable', 'integer', 'min:1', 'max:1440'], 'recargo' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'respaldo_he' => ['nullable', 'in:pacto,conocimiento_empleador'], 'pacto_desde' => ['nullable', 'date_format:Y-m-d'],
            'pacto_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:pacto_desde'], 'remuneracion_bruta' => $this->decimal(false),
            'pago_directo_institucion' => ['sometimes', 'boolean'], 'autorizacion' => ['nullable', 'string', 'min:10', 'max:1000'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000']]);
        foreach (['cantidad', 'valor', 'recargo', 'remuneracion_bruta', 'liquido_legal', 'base_imponible'] as $campo) {
            if (isset($d[$campo])) {
                $d[$campo] = ReglasChile::texto(ReglasChile::decimal($d[$campo]));
            }
        }

        return $d;
    }

    private function decimal(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'];
    }

    private function vigencia(): array
    {
        return ['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde']];
    }
}
