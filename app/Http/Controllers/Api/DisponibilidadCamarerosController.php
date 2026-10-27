<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Operacion\ServicioDisponibilidadCamareros;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;

class DisponibilidadCamarerosController extends Controller
{
    public function index(ServicioDisponibilidadCamareros $servicio, ServicioTemporadaActiva $temporadas)
    {
        return response()->json(['data' => $servicio->consultar($temporadas->obtener())])->header('Cache-Control', 'no-store, private');
    }

    public function latido(Request $request, ContextoOperacional $contexto, ServicioDisponibilidadCamareros $servicio)
    {
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $servicio->latido($usuario, $dispositivo, $usuario->currentAccessToken()?->getKey());

        return response()->noContent();
    }
}
