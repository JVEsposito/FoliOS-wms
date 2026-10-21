<?php

namespace App\Comex\Http\Controllers;

use App\Comex\Comercial\CatalogoComercial;
use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\LectorOperacion;
use App\Comex\Models\EventoComercial;
use App\Comex\Models\LugarComercial;
use App\Comex\Models\ParteComercial;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CatalogoComercialController extends Controller
{
    public function contexto(Request $r, EmbarquesOperacion $operacion): JsonResponse
    {
        return response()->json(['roles' => CatalogoComercial::ROLES, 'tipos_lugar' => CatalogoComercial::LUGARES, 'paises' => $operacion->paises(), 'permisos' => ['gestionar' => $r->user()->can('gestionar-comercial-comex'), 'aprobar' => $r->user()->can('aprobar-comercial-comex')]]);
    }

    public function partes(Request $r): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100'], 'rol' => ['nullable', Rule::in(CatalogoComercial::ROLES)], 'activo' => ['nullable', 'boolean']]);

        return response()->json(['data' => ParteComercial::query()->when($d['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('razon_social', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')->orWhere('tax_id', 'like', '%'.$s.'%')))->when($d['rol'] ?? null, fn ($q, $s) => $q->whereJsonContains('roles', $s))->when(isset($d['activo']), fn ($q) => $q->where('activo', $d['activo']))->orderBy('razon_social')->paginate(25)]);
    }

    public function guardarParte(Request $r, CatalogoComercial $g, ?ParteComercial $parte = null): JsonResponse
    {
        $d = $r->validate([...$this->comunes($parte !== null), 'pais_iso2' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'], 'tax_id' => ['required', 'string', 'max:80'], 'razon_social' => ['required', 'string', 'max:200'],
            'direccion' => ['required', 'string', 'max:300'], 'ciudad' => ['required', 'string', 'max:120'], 'roles' => ['required', 'array', 'min:1', 'max:13'], 'roles.*' => ['required', Rule::in(CatalogoComercial::ROLES), 'distinct'],
            'contacto' => ['nullable', 'string', 'max:200'], 'cliente_operacion_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $g->parte($d, $r->user(), $parte)], $parte ? 200 : 201);
    }

    public function lugares(Request $r): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100'], 'tipo' => ['nullable', Rule::in(CatalogoComercial::LUGARES)], 'activo' => ['nullable', 'boolean']]);

        return response()->json(['data' => LugarComercial::query()->when($d['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('nombre', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')->orWhere('codigo_externo', 'like', '%'.$s.'%')))->when($d['tipo'] ?? null, fn ($q, $s) => $q->where('tipo', $s))->when(isset($d['activo']), fn ($q) => $q->where('activo', $d['activo']))->orderBy('nombre')->paginate(25)]);
    }

    public function guardarLugar(Request $r, CatalogoComercial $g, ?LugarComercial $lugar = null): JsonResponse
    {
        $d = $r->validate([...$this->comunes($lugar !== null), 'tipo' => ['required', Rule::in(CatalogoComercial::LUGARES)], 'nombre' => ['required', 'string', 'max:200'], 'pais_iso2' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'localidad' => ['required', 'string', 'max:120'], 'direccion' => ['nullable', 'string', 'max:300'], 'codigo_externo' => ['nullable', 'string', 'max:50'], 'puerto_id' => ['nullable', 'uuid', Rule::exists('comex.cx_lugares_comerciales', 'id')]]);

        return response()->json(['data' => $g->lugar($d, $r->user(), $lugar)], $lugar ? 200 : 201);
    }

    public function clientes(Request $r, LectorOperacion $lector): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $lector->tabla('clientes')->when($d['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('nombre', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')))->orderBy('nombre')->paginate(25, ['id', 'codigo', 'nombre'])]);
    }

    public function historial(Request $r): JsonResponse
    {
        $d = $r->validate(['entidad' => ['required', 'in:parte,lugar'], 'entidad_id' => ['required', 'uuid']]);

        return response()->json(['data' => EventoComercial::query()->where($d)->orderByDesc('id')->paginate(50)]);
    }

    private function comunes(bool $edicion): array
    {
        return ['operacion_id' => ['required', 'uuid'], 'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'], 'activo' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0'],
            'respaldo' => ['required', 'string', 'min:10', 'max:1000'], 'motivo' => [$edicion ? 'required' : 'nullable', 'string', 'min:10', 'max:1000']];
    }
}
