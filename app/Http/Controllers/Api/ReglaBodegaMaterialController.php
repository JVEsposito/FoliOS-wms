<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Http\Controllers\Controller;
use App\Services\Materiales\ServicioAsignacionBodegaMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReglaBodegaMaterialController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $p = DB::table('reglas_bodegas_materiales as r')->join('destinos_materiales as a', 'a.id', '=', 'r.almacen_material_id')->leftJoin('clientes as c', 'c.id', '=', 'r.cliente_id')
            ->select('r.*', 'a.nombre as bodega', 'a.activo as bodega_activa', 'c.nombre as cliente')->orderByDesc('r.activo')->orderBy('r.prioridad')->orderBy('r.id')->paginate(25);

        return response()->json(['data' => $p->items(), 'meta' => ['pagina' => $p->currentPage(), 'paginas' => $p->lastPage()],
            'almacenes' => DB::table('destinos_materiales')->where('tipo', 'fisica')->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'activo']),
            'clientes' => DB::table('clientes')->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'puede_configurar' => Gate::allows('configurar-capacidad-materiales')])->header('Cache-Control', 'no-store');
    }

    public function store(Request $r, ServicioAsignacionBodegaMaterial $s)
    {
        return response()->json(['data' => $s->guardar(null, $this->datos($r), $r->user())], 201);
    }

    public function update(Request $r, string $id, ServicioAsignacionBodegaMaterial $s)
    {
        return response()->json(['data' => $s->guardar($id, $this->datos($r), $r->user())]);
    }

    public function historial(Request $r)
    {
        $f = $r->validate(['regla_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $p = DB::table('eventos_reglas_bodegas_materiales')->when($f['regla_id'] ?? null, fn ($q, $id) => $q->where('regla_id', $id))->orderByDesc('ocurrido_at')->orderByDesc('id')->paginate(25, ['id', 'regla_id', 'usuario', 'antes', 'despues', 'motivo', 'ocurrido_at']);

        return response()->json(['data' => collect($p->items())->map(fn ($e) => [...(array) $e, 'antes' => $e->antes ? json_decode($e->antes, true) : null, 'despues' => json_decode($e->despues, true)]), 'meta' => ['pagina' => $p->currentPage(), 'paginas' => $p->lastPage()]])->header('Cache-Control', 'no-store');
    }

    private function datos(Request $r): array
    {
        $r->merge(['nombre' => trim((string) $r->input('nombre')), 'motivo' => trim((string) $r->input('motivo'))]);

        return $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'almacen_material_id' => ['required', 'uuid', 'exists:destinos_materiales,id'], 'cliente_id' => ['nullable', 'uuid', 'exists:clientes,id'],
            'categoria_operacional' => ['nullable', Rule::enum(CategoriaOperacionalMaterial::class)], 'prioridad' => ['required', 'integer', 'between:1,9999'],
            'activo' => ['required', 'boolean'], 'motivo' => ['required', 'string', 'min:5', 'max:1000']]);
    }
}
