<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Materiales\ServicioBodegasMateriales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BodegaMaterialController extends Controller
{
    public function index(ServicioBodegasMateriales $s)
    {
        return response()->json(['data' => $s->catalogo(), 'puede_administrar' => Gate::allows('administrar-bodegas-materiales')]);
    }

    public function store(Request $r, ServicioBodegasMateriales $s)
    {
        return response()->json(['data' => $s->guardar(null, $this->datos($r), $r->user())], 201);
    }

    public function update(Request $r, string $id, ServicioBodegasMateriales $s)
    {
        return response()->json(['data' => $s->guardar($id, $this->datos($r), $r->user())]);
    }

    public function saldo(Request $r, string $id)
    {
        $d = $r->validate(['folio_id' => ['required', 'uuid']]);
        abort_unless(DB::table('destinos_materiales')->where('id', $id)->where('tipo', 'fisica')->where('activo', true)->exists(), 404);
        $saldo = DB::table('saldos_materiales_almacenes')->where('almacen_material_id', $id)->where('folio_id', $d['folio_id'])->first();

        return response()->json(['version' => (int) ($saldo?->version ?? 0)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function historial(Request $r)
    {
        $f = $r->validate(['almacen_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(DB::table('auditoria_bodegas_materiales as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('destinos_materiales as b', 'b.id', '=', 'a.almacen_material_id')->when($f['almacen_id'] ?? null, fn ($q, $id) => $q->where('a.almacen_material_id', $id))
            ->orderByDesc('a.ocurrido_at')->orderByDesc('a.id')->select('a.id', 'b.codigo', 'b.nombre', 'u.name as usuario', 'a.antes', 'a.despues', 'a.motivo', 'a.ocurrido_at')->paginate(25));
    }

    private function datos(Request $r): array
    {
        $r->merge(['codigo' => mb_strtoupper(trim((string) $r->input('codigo'))), 'nombre' => trim((string) $r->input('nombre')), 'motivo' => trim((string) $r->input('motivo'))]);

        return $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'], 'nombre' => ['required', 'string', 'min:2', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:1000'], 'activo' => ['required', 'boolean'], 'requiere_ubicacion_fisica' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000']]);
    }
}
