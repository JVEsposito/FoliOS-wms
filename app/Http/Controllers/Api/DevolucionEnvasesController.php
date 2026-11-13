<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\Envases\ServicioDevolucionEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Apoyo para registrar en Romana, o asociar en Cuenta Envases, la devolución de envases de un productor. */
class DevolucionEnvasesController extends Controller
{
    private const PERMISOS = ['operar-romana', 'consultar-romana', 'consultar-cuenta-envases'];

    public function productores(Cliente $cliente, ServicioDevolucionEnvases $servicio): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISOS), 403);

        return response()->json(['data' => $servicio->productores($cliente)])->header('Cache-Control', 'no-store, private');
    }

    public function salida(Request $request, ServicioDevolucionEnvases $servicio, ServicioTemporadaActiva $temporadaActiva): JsonResponse
    {
        abort_unless(Gate::any(self::PERMISOS), 403);
        $datos = $request->validate([
            'cliente_id' => ['required', 'uuid', 'exists:clientes,id'],
            'numero' => ['required', 'string', 'max:80'],
        ]);

        return response()->json(['data' => $servicio->salida(
            Cliente::query()->findOrFail($datos['cliente_id']),
            $temporadaActiva->obtener(),
            $datos['numero'],
        )])->header('Cache-Control', 'no-store, private');
    }
}
