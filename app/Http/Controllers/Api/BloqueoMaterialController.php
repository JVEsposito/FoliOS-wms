<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GestionarBloqueoMaterialRequest;
use App\Models\EventoBloqueoMaterial;
use App\Models\FolioMaterial;
use App\Services\Materiales\ServicioBloqueoMaterial;
use Illuminate\Http\JsonResponse;

class BloqueoMaterialController extends Controller
{
    public function bloquear(
        GestionarBloqueoMaterialRequest $request,
        FolioMaterial $folioMaterial,
        ServicioBloqueoMaterial $servicio,
    ): JsonResponse {
        return response()->json(['data' => $this->serializar($servicio->bloquear(
            $folioMaterial,
            $request->validated('operacion_id'),
            $request->validated('motivo'),
            $request->user(),
        ))]);
    }

    public function liberar(
        GestionarBloqueoMaterialRequest $request,
        FolioMaterial $folioMaterial,
        ServicioBloqueoMaterial $servicio,
    ): JsonResponse {
        return response()->json(['data' => $this->serializar($servicio->liberar(
            $folioMaterial,
            $request->validated('operacion_id'),
            $request->validated('motivo'),
            $request->user(),
        ))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(EventoBloqueoMaterial $evento): array
    {
        $material = $evento->folioMaterial;
        $folio = $material->folio;
        $cliente = $material->item?->cliente?->cliente;
        $posicion = $folio?->ubicacionActual?->posicion;

        return [
            'id' => $evento->id,
            'operacion_id' => $evento->operacion_id,
            'tipo' => $evento->tipo->value,
            'folio' => [
                'id' => $material->folio_id,
                'numero_folio' => $folio?->numero_folio,
                'estado_operacional' => $folio?->estado_operacional?->value,
                'motivo_bloqueo' => $material->motivo_bloqueo,
                'ubicacion' => $posicion ? [
                    'camara' => $posicion->camara?->codigo,
                    'posicion' => $posicion->etiqueta,
                ] : null,
            ],
            'cliente' => $cliente ? [
                'id' => $cliente->id,
                'codigo' => $cliente->codigo,
                'nombre' => $cliente->nombre,
            ] : null,
            'estado_anterior' => $evento->estado_anterior->value,
            'estado_resultante' => $evento->estado_resultante->value,
            'motivo' => $evento->motivo,
            'usuario' => $evento->usuario ? [
                'id' => $evento->usuario->id,
                'nombre' => $evento->usuario->name,
            ] : null,
            'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
        ];
    }
}
