<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Materiales\ServicioBodegaMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BodegaMaterialController extends Controller
{
    public function index()
    {
        return response()->json(['data' => DB::table('destinos_materiales')->where('tipo', 'fisica')->orderByDesc('activo')->orderBy('nombre')->get(),
            'puede_configurar' => Gate::allows('configurar-capacidad-materiales'),
            'zona_horaria' => config('app.operational_timezone')])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, ServicioBodegaMaterial $servicio)
    {
        return response()->json(['data' => $servicio->guardar(null, $this->datos($request), $request->user())], 201);
    }

    public function update(Request $request, string $id, ServicioBodegaMaterial $servicio)
    {
        return response()->json(['data' => $servicio->guardar($id, $this->datos($request), $request->user())]);
    }

    public function historial(Request $r)
    {
        $f = $r->validate(['almacen_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $p = DB::table('eventos_bodegas_materiales')->when($f['almacen_id'] ?? null, fn ($q, $id) => $q->where('almacen_material_id', $id))
            ->select('id', 'almacen_material_id', 'user_id', 'usuario', 'anterior', 'resultado', 'ocurrido_at')
            ->orderByDesc('ocurrido_at')->orderByDesc('id')->paginate(20);

        return response()->json(['data' => collect($p->items())->map(fn ($e) => [...(array) $e,
            'anterior' => $e->anterior ? json_decode($e->anterior, true) : null, 'resultado' => json_decode($e->resultado, true)]),
            'meta' => ['pagina' => $p->currentPage(), 'paginas' => $p->lastPage()]])->header('Cache-Control', 'no-store');
    }

    private function datos(Request $r): array
    {
        $r->merge(['codigo' => mb_strtoupper(trim((string) $r->input('codigo'))), 'nombre' => trim((string) $r->input('nombre'))]);

        return $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'], 'nombre' => ['required', 'string', 'min:3', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:1000'], 'activo' => ['required', 'boolean']]);
    }
}
