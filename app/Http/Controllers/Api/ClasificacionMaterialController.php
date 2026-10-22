<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarCategoriaComercialMaterialRequest;
use App\Http\Requests\GuardarTipoItemMaterialRequest;
use App\Models\CategoriaComercialMaterial;
use App\Models\TipoItemMaterial;
use App\Services\Materiales\ServicioClasificacionMaterial;
use Illuminate\Http\JsonResponse;

class ClasificacionMaterialController extends Controller
{
    public function index(ServicioClasificacionMaterial $servicio): JsonResponse
    {
        return response()->json(['data' => $servicio->catalogos()]);
    }

    public function storeCategoria(GuardarCategoriaComercialMaterialRequest $request, ServicioClasificacionMaterial $servicio): JsonResponse
    {
        $categoria = $servicio->guardarCategoria($request->validated(), $request->user());

        return response()->json(['data' => $categoria->only(['id', 'nombre', 'activo'])], 201);
    }

    public function updateCategoria(GuardarCategoriaComercialMaterialRequest $request, CategoriaComercialMaterial $categoriaComercialMaterial, ServicioClasificacionMaterial $servicio): JsonResponse
    {
        $categoria = $servicio->guardarCategoria($request->validated(), $request->user(), $categoriaComercialMaterial);

        return response()->json(['data' => $categoria->only(['id', 'nombre', 'activo'])]);
    }

    public function storeTipo(GuardarTipoItemMaterialRequest $request, ServicioClasificacionMaterial $servicio): JsonResponse
    {
        $tipo = $servicio->guardarTipo($request->validated(), $request->user());

        return response()->json(['data' => $tipo->only(['id', 'nombre', 'activo', 'categoria_operacional'])], 201);
    }

    public function updateTipo(GuardarTipoItemMaterialRequest $request, TipoItemMaterial $tipoItemMaterial, ServicioClasificacionMaterial $servicio): JsonResponse
    {
        $tipo = $servicio->guardarTipo($request->validated(), $request->user(), $tipoItemMaterial);

        return response()->json(['data' => $tipo->only(['id', 'nombre', 'activo', 'categoria_operacional'])]);
    }
}
