<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPlanoPlantaRequest;
use App\Models\PlanoPlanta;
use App\Services\Operacion\ServicioPlanoPlanta;
use App\Services\Operacion\ServicioRedPlanta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanoPlantaController extends Controller
{
    public function update(
        GuardarPlanoPlantaRequest $request,
        ServicioPlanoPlanta $servicio,
        ServicioRedPlanta $red,
    ): JsonResponse {
        $plano = $servicio->guardar($request->validated(), $request->user());

        return response()->json([
            'data' => [
                'nombre' => $plano->nombre,
                'proporcion' => $servicio->proporcion($plano),
                'version' => $plano->version,
                'elementos' => $plano->elementos,
                'conexiones' => $plano->conexiones ?? [],
                'red' => $red->resumen($plano->elementos, $plano->conexiones ?? []),
                'actualizado_at' => $plano->updated_at?->toAtomString(),
            ],
        ]);
    }

    public function recorrido(Request $request, ServicioRedPlanta $red): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['required', 'uuid'],
            'hacia' => ['required', 'uuid'],
        ]);
        $plano = PlanoPlanta::query()
            ->where('codigo', ServicioPlanoPlanta::CODIGO_PRINCIPAL)
            ->first();
        $recorrido = $plano
            ? $red->recorrido($plano->elementos, $plano->conexiones ?? [], $datos['desde'], $datos['hacia'])
            : null;

        abort_if($recorrido === null, 404, 'No existe un recorrido conectado entre esos elementos del plano.');

        return response()->json([
            'data' => [
                ...$recorrido,
                'version_plano' => $plano->version,
            ],
        ]);
    }
}
