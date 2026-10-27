<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Validacion\ServicioPrecargaValidacionOrigen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Datos de planta de un pallet para que la PDA parta con ellos al validarlo. Solo lectura. */
class PrecargaValidacionOrigenController extends Controller
{
    public function __invoke(Request $request, ServicioPrecargaValidacionOrigen $precarga): JsonResponse
    {
        $datos = $request->validate([
            'numero_folio' => ['required', 'string', 'max:50'],
        ]);

        return response()->json(['data' => $precarga->paraFolio($datos['numero_folio'])]);
    }
}
