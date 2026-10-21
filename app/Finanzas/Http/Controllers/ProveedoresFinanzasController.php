<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Models\CuentaCompra;
use App\Finanzas\Models\EventoCompra;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Finanzas\Proveedores\DirectorioProveedores;
use App\Finanzas\Proveedores\GestorDocumentosCompra;
use App\Finanzas\Proveedores\PlanCuentasCompra;
use App\Http\Controllers\Controller;
use App\Rules\RutChileno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProveedoresFinanzasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => ProveedorFinanciero::query()->when(filled($datos['buscar'] ?? null), fn ($q) => $q->where(fn ($q) => $q->where('razon_social', 'like', '%'.$datos['buscar'].'%')->orWhere('rut', 'like', '%'.$datos['buscar'].'%')))->orderBy('razon_social')->paginate(30), 'cuentas' => CuentaCompra::query()->orderBy('codigo')->get(), 'tipos' => GestorDocumentosCompra::TIPOS, 'cuentas_control' => ['anticipos' => config('finanzas-compras.anticipos')], 'puede_gestionar' => $request->user()->can('gestionar-proveedores-finanzas')]);
    }

    public function materiales(Request $request, DirectorioProveedores $directorio): JsonResponse
    {
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $directorio->materiales($datos['buscar'] ?? '')]);
    }

    public function store(Request $request, DirectorioProveedores $directorio): JsonResponse
    {
        return response()->json(['data' => $directorio->guardar($this->datos($request), $request->user())], 201);
    }

    public function update(Request $request, ProveedorFinanciero $proveedor, DirectorioProveedores $directorio): JsonResponse
    {
        return response()->json(['data' => $directorio->guardar($this->datos($request, true), $request->user(), $proveedor)]);
    }

    private function datos(Request $request, bool $editar = false): array
    {
        return $request->validate([
            'rut' => ['required', 'string', 'regex:/^[0-9.\s-]+[0-9kK]$/', 'max:16', new RutChileno],
            'razon_social' => ['required', 'string', 'max:180'], 'proveedor_material_id' => ['nullable', 'uuid'],
            'giro' => ['nullable', 'string', 'max:180'], 'direccion' => ['nullable', 'string', 'max:250'],
            'contacto' => ['nullable', 'string', 'max:180'], 'email' => ['nullable', 'email', 'max:180'],
            'telefono' => ['nullable', 'string', 'max:40'], 'categoria' => ['nullable', 'string', 'max:80'],
            'activo' => ['required', 'boolean'], 'pagos_bloqueados' => ['required', 'boolean'],
            'version' => [$editar ? 'required' : 'sometimes', 'integer', 'min:1'],
            'motivo' => [$editar ? 'required' : 'nullable', 'string', 'min:5', 'max:500'],
        ]);
    }

    public function plan(Request $request, PlanCuentasCompra $plan): JsonResponse
    {
        $plan->inicializar($request->user());

        return response()->json(['data' => CuentaCompra::query()->orderBy('codigo')->get()]);
    }

    public function cuenta(Request $request, PlanCuentasCompra $plan): JsonResponse
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,30}$/'], 'nombre' => ['required', 'string', 'max:180'], 'tipo' => ['required', Rule::in(['gasto', 'activo', 'iva', 'pasivo', 'tesoreria'])], 'activa' => ['required', 'boolean']]);

        return response()->json(['data' => $plan->guardar($datos, $request->user())]);
    }

    public function historial(string $entidad, string $id): JsonResponse
    {
        return response()->json(['data' => EventoCompra::query()->where('entidad', $entidad)->where('entidad_id', $id)->orderByDesc('id')->paginate(50)]);
    }
}
