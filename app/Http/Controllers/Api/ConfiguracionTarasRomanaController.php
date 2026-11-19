<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Romana\ServicioTarasRomana;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConfiguracionTarasRomanaController extends Controller
{
    public function actualizar(Request $request, string $tipo, ServicioTarasRomana $taras): JsonResponse
    {
        Gate::authorize('configurar-taras-romana');

        $datos = $request->validate([
            'tipo_envase' => ['prohibited'],
            'tara_unitaria' => ['required', 'numeric', 'gt:0', 'max:1000', 'decimal:0,3'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if (! in_array($tipo, ['bins', 'totes'], true)) {
            abort(404);
        }

        return response()->json(['data' => $taras->actualizar(
            $tipo,
            round((float) $datos['tara_unitaria'], 3),
            trim($datos['motivo']),
            $request->user(),
        )])->header('Cache-Control', 'no-store, private');
    }
}
