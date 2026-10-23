<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperacionIntegracion;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperacionIntegracionController extends Controller
{
    public function show(Request $request, OperacionIntegracion $operacionIntegracion, ServicioOperacionesIntegracion $servicio)
    {
        Gate::authorize($operacionIntegracion->tipo === 'stock' ? 'consultar-validaciones-pallet' : 'administrar-accesos');
        abort_unless($operacionIntegracion->tipo === 'stock' || $operacionIntegracion->user_id === $request->user()->id, 403);

        return response()->json(['operacion' => $servicio->recurso($operacionIntegracion)], 200, ['Cache-Control' => 'private, no-store']);
    }
}
