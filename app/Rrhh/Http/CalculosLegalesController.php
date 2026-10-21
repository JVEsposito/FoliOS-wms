<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Models\CalculoLegal;
use App\Rrhh\Models\IndiceNomina;
use App\Rrhh\Nomina\PreparacionLegal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CalculosLegalesController extends Controller
{
    public function indices(Request $r): JsonResponse
    {
        $d = $r->validate(['mes' => ['required', 'date_format:Y-m'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => IndiceNomina::query()->where('mes', $d['mes'])->orderByDesc('version')->paginate(25)]);
    }

    public function indice(Request $r, PreparacionLegal $g): JsonResponse
    {
        $d = $r->validate(['mes' => ['required', 'date_format:Y-m'], 'uf' => $this->decimal(), 'utm' => $this->decimal(), 'fecha_uf' => ['required', 'date_format:Y-m-d'],
            'version_anterior' => ['nullable', 'integer', 'min:0'], 'comisiones' => ['sometimes', 'array', 'max:100'], 'comisiones.*' => ['required', 'array:institucion_id,tasa'],
            'comisiones.*.institucion_id' => ['required', 'uuid', 'distinct', Rule::exists('rrhh.rh_instituciones', 'id')], 'comisiones.*.tasa' => $this->decimal(),
            'respaldo' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->indice($d, $r->user())], 201);
    }

    public function calculos(Request $r): JsonResponse
    {
        $d = $r->validate(['persona_id' => ['required', 'uuid'], 'mes' => ['required', 'date_format:Y-m'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => CalculoLegal::query()->where('persona_id', $d['persona_id'])->where('mes', $d['mes'])->orderByDesc('created_at')->orderBy('id')->paginate(25)]);
    }

    public function simular(Request $r, PreparacionLegal $g): JsonResponse
    {
        return response()->json(['data' => $g->calcular($this->datos($r, false))]);
    }

    public function guardar(Request $r, PreparacionLegal $g): JsonResponse
    {
        return response()->json(['data' => $g->guardar($this->datos($r, true), $r->user())], 201);
    }

    private function datos(Request $r, bool $guardar): array
    {
        $tipo = $r->validate(['tipo' => ['required', 'in:anticipo50,anual50,reparto47,impuesto,honorarios,cotizaciones']])['tipo'];
        $campos = match ($tipo) {
            'anticipo50' => ['base_computable' => $this->decimal(), 'imm_aplicable' => $this->decimal()],
            'anual50' => ['remuneraciones_computables' => $this->decimal(), 'imm_diciembre' => $this->decimal(), 'meses_computables' => $this->decimal(),
                'anticipos' => ['sometimes', 'array', 'max:12'], 'anticipos.*' => ['array:mes,monto,factor_reajuste'], 'anticipos.*.mes' => ['required', 'date_format:Y-m', 'distinct'], 'anticipos.*.monto' => $this->decimal(), 'anticipos.*.factor_reajuste' => ['required', 'string', 'regex:/^(?:[0-9](?:\.\d{1,8})?)$/']],
            'reparto47' => ['utilidad_liquida' => $this->decimal(), 'porcentaje_reparto' => $this->decimal(), 'remuneracion_persona' => $this->decimal(), 'remuneracion_total' => $this->decimal()],
            'impuesto' => ['base_tributaria' => $this->decimal()],
            'honorarios' => ['bruto' => $this->decimal(), 'retiene_receptor' => ['required', 'boolean']],
            'cotizaciones' => ['base_imponible' => $this->decimal(), 'adicional_accidentes' => $this->decimal(), 'cic_completa' => ['required', 'boolean'], 'caso_general' => ['required', 'accepted'],
                'afc_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')], 'fapp_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_instituciones', 'id')]],
        };
        $reglas = [];
        foreach ($campos as $campo => $validacion) {
            $reglas['datos.'.$campo] = $validacion;
        }
        $d = $r->validate(['operacion_id' => [$guardar ? 'required' : 'nullable', 'uuid'], 'tipo' => ['required', 'string'], 'mes' => ['required', 'date_format:Y-m'],
            'persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')], 'contrato_id' => [$tipo === 'cotizaciones' ? 'required' : 'nullable', 'uuid', Rule::exists('rrhh.rh_contratos', 'id')],
            'parametro_id' => [in_array($tipo, ['impuesto', 'cotizaciones'], true) ? 'required' : 'nullable', 'uuid', Rule::exists('rrhh.rh_indices_nomina', 'id')],
            'respaldo' => ['required', 'string', 'min:10', 'max:1000'], 'datos' => ['required', 'array:'.implode(',', array_filter(array_keys($campos), fn ($k) => ! str_contains($k, '.')))], ...$reglas]);
        if ($tipo === 'anual50') {
            foreach ($d['datos']['anticipos'] ?? [] as $a) {
                if (substr($a['mes'], 0, 4) !== substr($d['mes'], 0, 4)) {
                    throw ValidationException::withMessages(['anticipos' => 'Los anticipos deben pertenecer al ejercicio seleccionado.']);
                }
            }
        }

        return $d;
    }

    private function decimal(): array
    {
        return ['required', 'string', 'regex:/^\d{1,15}(?:\.\d{1,6})?$/'];
    }
}
