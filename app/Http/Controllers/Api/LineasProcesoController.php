<?php

namespace App\Http\Controllers\Api;

use App\Enums\TurnoProceso;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarLineaProcesoRequest;
use App\Models\LineaProceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LineasProcesoController extends Controller
{
    public function catalogos(): JsonResponse
    {
        return response()->json([
            'lineas' => LineaProceso::query()
                ->ordenadas()
                ->get()
                ->map(fn (LineaProceso $linea): array => $this->linea($linea))
                ->values(),
            'turnos' => TurnoProceso::catalogo(),
        ]);
    }

    public function update(ActualizarLineaProcesoRequest $request, LineaProceso $lineaProceso): JsonResponse
    {
        $datos = $request->validated();

        $linea = DB::transaction(function () use ($lineaProceso, $datos, $request): LineaProceso {
            $linea = LineaProceso::query()->lockForUpdate()->findOrFail($lineaProceso->id);
            if ($linea->version !== (int) $datos['version']) {
                throw new ConflictoOperacion('La línea fue modificada por otra persona. Actualiza antes de guardar.');
            }

            $linea->update([
                ...array_intersect_key($datos, array_flip(['nombre', 'rendimiento_kg_hora', 'activa'])),
                'version' => $linea->version + 1,
                'actualizado_por_user_id' => $request->user()->id,
            ]);

            return $linea->refresh();
        });

        return response()->json(['data' => $this->linea($linea)]);
    }

    /** @return array<string, mixed> */
    private function linea(LineaProceso $linea): array
    {
        return [
            'id' => $linea->id,
            'codigo' => $linea->codigo,
            'nombre' => $linea->nombre,
            'rendimiento_kg_hora' => (float) $linea->rendimiento_kg_hora,
            'activa' => $linea->activa,
            'version' => $linea->version,
        ];
    }
}
