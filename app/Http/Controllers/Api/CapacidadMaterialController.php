<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Materiales\ServicioCapacidadMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CapacidadMaterialController extends Controller
{
    public function catalogo(ServicioCapacidadMaterial $servicio)
    {
        return $this->json([...$servicio->catalogo(), 'zona_horaria' => config('app.operational_timezone'), 'permisos' => [
            'configurar' => Gate::allows('configurar-capacidad-materiales'),
            'medir' => Gate::allows('medir-cargas-materiales'),
        ]]);
    }

    public function storeTipo(Request $request, ServicioCapacidadMaterial $servicio)
    {
        return $this->json(['data' => $servicio->guardarTipo(null, $this->tipo($request), $request->user())], 201);
    }

    public function updateTipo(Request $request, string $id, ServicioCapacidadMaterial $servicio)
    {
        return $this->json(['data' => $servicio->guardarTipo($id, $this->tipo($request), $request->user())]);
    }

    private function tipo(Request $r): array
    {
        $r->merge(['codigo' => mb_strtoupper(trim((string) $r->input('codigo'))), 'nombre' => trim((string) $r->input('nombre'))]);

        return $r->validate([...$this->operacion(),
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'nombre' => ['required', 'string', 'min:2', 'max:150'], 'activo' => ['required', 'boolean'],
            'largo_m' => $this->dimension(), 'ancho_m' => $this->dimension(), 'altura_total_m' => $this->dimension(),
            'max_niveles' => ['required', 'integer', 'between:1,20'], 'peso_maximo_kg' => $this->peso(),
        ]);
    }

    public function guardarAlmacen(Request $r, string $id, ServicioCapacidadMaterial $servicio)
    {
        $d = $r->validate([...$this->operacion(), 'largo_m' => $this->dimension(max: 1000), 'ancho_m' => $this->dimension(max: 1000),
            'altura_util_m' => $this->dimension(max: 50), 'zonas' => ['required', 'array', 'min:1', 'max:100'],
            'zonas.*' => ['required', 'array:id,nombre,x_m,y_m,largo_m,ancho_m,altura_util_m,max_niveles,permite_giro,limite_pallets,peso_maximo_kg,tipo_pallet_ids,modo,hueco_largo_m,hueco_ancho_m,hueco_altura_m'],
            'zonas.*.id' => ['required', 'uuid', 'distinct'], 'zonas.*.nombre' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'zonas.*.x_m' => ['required', 'numeric', 'between:0,1000', 'decimal:0,3'],
            'zonas.*.y_m' => ['required', 'numeric', 'between:0,1000', 'decimal:0,3'],
            'zonas.*.largo_m' => $this->dimension(max: 1000), 'zonas.*.ancho_m' => $this->dimension(max: 1000),
            'zonas.*.altura_util_m' => $this->dimension(max: 50), 'zonas.*.max_niveles' => ['required', 'integer', 'between:1,20'],
            'zonas.*.permite_giro' => ['required', 'boolean'], 'zonas.*.limite_pallets' => ['nullable', 'integer', 'between:1,10000000'],
            'zonas.*.peso_maximo_kg' => $this->peso(), 'zonas.*.tipo_pallet_ids' => ['required', 'array', 'min:1', 'max:100'],
            'zonas.*.tipo_pallet_ids.*' => ['required', 'uuid', 'exists:tipos_pallet_materiales,id'],
            'zonas.*.modo' => ['sometimes', Rule::in(['piso', 'rack'])],
            'zonas.*.hueco_largo_m' => $this->dimension(false), 'zonas.*.hueco_ancho_m' => $this->dimension(false),
            'zonas.*.hueco_altura_m' => $this->dimension(false),
        ]);

        return $this->json(['data' => $servicio->guardarAlmacen($id, $d, $r->user())]);
    }

    public function cargas(Request $r, ServicioCapacidadMaterial $servicio)
    {
        $f = $r->validate(['almacen_id' => ['required', 'uuid'], 'busqueda' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'between:1,100000']]);

        return $this->json($servicio->cargas($f));
    }

    public function guardarCarga(Request $r, string $id, ServicioCapacidadMaterial $servicio)
    {
        $d = $r->validate([...$this->operacion(), ...$this->reglasCarga(),
            'bultos_fisicos' => ['required', 'integer', 'between:1,100000'], 'saldo_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['data' => $servicio->guardarCarga($id, $d, $r->user())]);
    }

    public function simular(Request $r, string $id, ServicioCapacidadMaterial $servicio)
    {
        return $this->json(['data' => $servicio->simular($id, $r->validate($this->reglasCarga()))]);
    }

    public function historial(Request $r)
    {
        $f = $r->validate(['entidad' => ['nullable', Rule::in(['tipo', 'almacen', 'carga'])], 'registro_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'between:1,100000']]);
        $p = DB::table('eventos_capacidad_materiales as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->when($f['entidad'] ?? null, fn ($q, $v) => $q->where('e.entidad', $v))
            ->when($f['registro_id'] ?? null, fn ($q, $v) => $q->where('e.registro_id', $v))
            ->select('e.id', 'e.entidad', 'e.registro_id', 'e.user_id', 'u.name as usuario', 'e.anterior', 'e.resultado', 'e.ocurrido_at')
            ->orderByDesc('e.ocurrido_at')->orderByDesc('e.id')->paginate(25, ['*'], 'page', $f['page'] ?? 1);

        return $this->json(['data' => collect($p->items())->map(fn ($e) => [...(array) $e,
            'anterior' => $e->anterior ? json_decode($e->anterior, true) : null, 'resultado' => json_decode($e->resultado, true)]),
            'meta' => ['pagina' => $p->currentPage(), 'paginas' => $p->lastPage(), 'total' => $p->total()]]);
    }

    private function operacion(): array
    {
        return ['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0']];
    }

    private function reglasCarga(): array
    {
        return ['tipo_pallet_id' => ['required', 'uuid', 'exists:tipos_pallet_materiales,id'],
            'largo_m' => $this->dimension(false), 'ancho_m' => $this->dimension(false), 'altura_total_m' => $this->dimension(false),
            'max_niveles' => ['nullable', 'integer', 'between:1,20'], 'peso_unitario_kg' => $this->peso()];
    }

    private function dimension(bool $required = true, int $max = 20): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'min:0.001', "max:$max", 'decimal:0,3'];
    }

    private function peso(): array
    {
        return ['nullable', 'numeric', 'between:0.001,1000000', 'decimal:0,3'];
    }

    private function json(array $datos, int $status = 200)
    {
        return response()->json($datos, $status)->header('Cache-Control', 'no-store');
    }
}
