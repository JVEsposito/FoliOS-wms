<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EliminarFolioManualRequest;
use App\Models\Folio;
use App\Services\Folios\ServicioEliminacionFolioManual;
use Illuminate\Http\JsonResponse;

class EliminacionFolioManualController extends Controller
{
    public function __invoke(
        EliminarFolioManualRequest $request,
        Folio $folio,
        ServicioEliminacionFolioManual $servicio,
    ): JsonResponse {
        $eliminacion = $servicio->eliminar($folio, $request->validated(), $request->user());

        return response()->json(['data' => [
            'id' => $eliminacion->id,
            'folio_id' => $eliminacion->folio_id,
            'numero_folio' => $eliminacion->numero_folio,
            'numero_folio_archivado' => $eliminacion->numero_folio_archivado,
            'motivo' => $eliminacion->motivo,
            'eliminado_at' => $eliminacion->eliminado_at?->toAtomString(),
            'eliminado_por' => $eliminacion->eliminadoPor?->name,
            'ubicacion_liberada' => $eliminacion->snapshot['ubicacion'] ?? null,
        ]]);
    }
}
