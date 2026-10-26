<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversionUnidadMaterial;
use App\Models\EventoUnidadMaterial;
use App\Models\ItemMaterial;
use App\Models\UnidadMaterial;
use App\Services\Materiales\NombreClasificacionMaterial;
use App\Services\Materiales\ServicioUnidadesMateriales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnidadMaterialController extends Controller
{
    public function index(): JsonResponse
    {
        $alias = DB::table('alias_unidades_materiales')->orderBy('clave')->get()->groupBy('unidad_material_id');

        return response()->json(['data' => UnidadMaterial::orderByDesc('activa')->orderBy('nombre')->get()->map(fn ($u) => [...$u->toArray(), 'alias' => ($alias[$u->id] ?? collect())->pluck('clave')])]);
    }

    public function store(Request $request, ServicioUnidadesMateriales $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->guardarUnidad($this->datos($request))], 201);
    }

    public function update(Request $request, UnidadMaterial $unidadMaterial, ServicioUnidadesMateriales $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->guardarUnidad($this->datos($request), $unidadMaterial)]);
    }

    public function conversiones(ItemMaterial $itemMaterial): JsonResponse
    {
        return response()->json(['data' => ConversionUnidadMaterial::where('item_material_id', $itemMaterial->id)->with('origen')->get(), 'unidad_base' => $itemMaterial->unidad_medida]);
    }

    public function guardarConversion(Request $request, ItemMaterial $itemMaterial, ServicioUnidadesMateriales $servicio): JsonResponse
    {
        $datos = $request->validate([
            'unidad_origen' => ['required', 'string', 'max:40'], 'factor' => ['required', 'numeric', 'gt:0', 'max:99999999.999999', 'decimal:0,6'],
            'activa' => ['required', 'boolean'], 'version_conocida' => ['required', 'integer', 'min:0'], 'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return response()->json(['data' => $servicio->guardarConversion($itemMaterial, $datos)]);
    }

    public function historial(): JsonResponse
    {
        return response()->json(['data' => EventoUnidadMaterial::query()->leftJoin('users', 'users.id', '=', 'eventos_unidad_materiales.user_id')
            ->latest('eventos_unidad_materiales.created_at')->limit(100)->get(['eventos_unidad_materiales.*', 'users.name as responsable'])]);
    }

    private function datos(Request $request): array
    {
        if (is_string($request->input('nombre'))) {
            $request->merge(['nombre' => NombreClasificacionMaterial::mayusculas($request->input('nombre'))]);
        }

        return $request->validate([
            'codigo' => ['required', 'string', 'max:40'], 'nombre' => ['required', 'string', 'min:1', 'max:100'],
            'alias' => ['present', 'array', 'max:20'], 'alias.*' => ['required', 'string', 'max:40', 'distinct'],
            'activa' => ['required', 'boolean'], 'version_conocida' => ['required', 'integer', 'min:0'], 'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
    }
}
