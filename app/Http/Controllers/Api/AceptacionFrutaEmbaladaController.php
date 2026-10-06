<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecepcionEmbalada\ServicioAceptacionFrutaEmbalada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AceptacionFrutaEmbaladaController extends Controller
{
    public function show(string $recepcion, ServicioAceptacionFrutaEmbalada $servicio): JsonResponse
    {
        Gate::authorize('consultar-validaciones-pallet');

        return response()->json(['data' => $servicio->estado($recepcion)]);
    }

    public function aceptar(string $recepcion, Request $request, ServicioAceptacionFrutaEmbalada $servicio): JsonResponse
    {
        Gate::authorize('validar-pallets');
        $datos = $request->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'size:64']]);

        return response()->json(['data' => $servicio->aceptar($recepcion, $datos, $request->user())]);
    }

    public function anular(string $recepcion, Request $request, ServicioAceptacionFrutaEmbalada $servicio): JsonResponse
    {
        Gate::authorize('corregir-validaciones-pallet');
        $datos = $request->validate(['operacion_id' => ['required', 'uuid'], 'motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $servicio->anular($recepcion, $datos, $request->user())]);
    }
}
