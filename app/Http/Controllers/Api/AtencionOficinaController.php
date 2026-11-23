<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Operacion\ServicioAtencionOficina;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtencionOficinaController extends Controller
{
    public function __invoke(
        Request $request,
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        ServicioAtencionOficina $atencion,
    ): JsonResponse {
        abort_unless($alcance->puedeAccederOficina($request->user()), 403);

        return response()->json([
            'data' => $atencion->obtener($request->user(), $temporadas->buscar()),
        ])->header('Cache-Control', 'no-store, private');
    }
}
