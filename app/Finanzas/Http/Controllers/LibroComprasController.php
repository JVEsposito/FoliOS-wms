<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Models\AsientoCompra;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibroComprasController extends Controller
{
    public function asientos(Request $request): JsonResponse
    {
        $d = $request->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'], 'proveedor_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => AsientoCompra::query()->with('lineas')->whereBetween('fecha', [$d['desde'], $d['hasta']])->when($d['proveedor_id'] ?? null, fn ($q, $id) => $q->where('proveedor_id', $id))->orderBy('fecha')->orderBy('created_at')->orderBy('id')->paginate(30)]);
    }
}
