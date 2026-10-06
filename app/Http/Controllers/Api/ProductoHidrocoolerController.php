<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\ProductoHidrocooler;
use App\Services\MateriaPrima\ControlCloroHidrocooler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductoHidrocoolerController extends Controller
{
    public function catalogo(ControlCloroHidrocooler $control): JsonResponse
    {
        Gate::authorize('consultar-hidrocooler-materia-prima');

        return response()->json([
            'data' => ProductoHidrocooler::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'unidad_dosis']),
            'rango_cloro_ppm' => $control->rango(),
        ]);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('administrar-accesos');

        return response()->json(['data' => ProductoHidrocooler::query()->with('actualizadoPor:id,name')->orderBy('nombre')->get()]);
    }

    public function eventos(ProductoHidrocooler $productoHidrocooler): JsonResponse
    {
        Gate::authorize('administrar-accesos');

        return response()->json(['data' => $productoHidrocooler->eventos()->with('usuario:id,name')->latest()->paginate(25)]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guardar($request);
    }

    public function update(Request $request, ProductoHidrocooler $productoHidrocooler): JsonResponse
    {
        return $this->guardar($request, $productoHidrocooler);
    }

    private function guardar(Request $request, ?ProductoHidrocooler $producto = null): JsonResponse
    {
        Gate::authorize('administrar-accesos');
        $request->merge(['nombre' => trim((string) $request->input('nombre')), 'unidad_dosis' => trim((string) $request->input('unidad_dosis'))]);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150', Rule::unique('productos_hidrocooler', 'nombre')->ignore($producto?->id)],
            'unidad_dosis' => ['required', 'string', 'max:30'],
            'activo' => ['required', 'boolean'],
            'version_conocida' => [$producto ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
        $resultado = DB::transaction(function () use ($request, $producto, $datos): ProductoHidrocooler {
            if ($producto) {
                $producto = ProductoHidrocooler::query()->lockForUpdate()->findOrFail($producto->id);
                if ($producto->version !== $datos['version_conocida']) {
                    throw new ConflictoOperacion('El producto cambió. Actualiza el catálogo antes de guardar.');
                }
            }
            $antes = $producto?->only('nombre', 'unidad_dosis', 'activo', 'version');
            $producto ??= new ProductoHidrocooler;
            $producto->fill([
                'nombre' => $datos['nombre'], 'unidad_dosis' => $datos['unidad_dosis'], 'activo' => $datos['activo'],
                'version' => $producto->exists ? $producto->version + 1 : 1,
                'actualizado_por_user_id' => $request->user()->id,
            ])->save();
            $producto->eventos()->create([
                'user_id' => $request->user()->id, 'antes' => $antes,
                'despues' => $producto->only('nombre', 'unidad_dosis', 'activo', 'version'),
            ]);

            return $producto->load('actualizadoPor:id,name');
        }, attempts: 3);

        return response()->json(['data' => $resultado], $producto ? 200 : 201);
    }
}
