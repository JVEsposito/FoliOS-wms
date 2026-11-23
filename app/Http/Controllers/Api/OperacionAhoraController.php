<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Operacion\ServicioOperacionAhora;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperacionAhoraController extends Controller
{
    public function __invoke(
        Request $request,
        ServicioOperacionAhora $servicio,
        ServicioTemporadaActiva $temporadas,
        AlcanceOperacionalUsuario $alcance,
    ): JsonResponse {
        $usuario = $request->user();
        $data = $servicio->obtener($temporadas->obtener(), Gate::allows('administrar-plano-planta'));
        $data['despacho']['puede_cerrar'] = $alcance->puedeConsultarCatalogoCargas($usuario)
            && $alcance->puedeConsultarCargas($usuario)
            && $alcance->puedeCerrarDespachoFrigorifico($usuario);

        return response()
            ->json(['data' => $data])
            ->header('Cache-Control', 'no-store, private');
    }
}
