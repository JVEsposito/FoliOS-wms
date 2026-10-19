<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Cierre\GestorCierres;
use App\Finanzas\Http\RespuestaPrefactura;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cierre y reapertura de meses de la prefactura. Responden con el mes como queda. */
class CierresPrefacturaController extends Controller
{
    public function __construct(
        private readonly GestorCierres $gestor,
        private readonly RespuestaPrefactura $respuesta,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $periodo = $this->respuesta->periodo($request->all(), obligatorio: true);
        $datos = $request->validate(
            ['confirmar_pendientes' => ['sometimes', 'boolean']],
            ['confirmar_pendientes.boolean' => 'La confirmación de pendientes no es válida.'],
        );

        $this->gestor->cerrar($periodo->clave(), (bool) ($datos['confirmar_pendientes'] ?? false), $request->user());

        return response()->json($this->respuesta->mes($request, $periodo), 201);
    }

    public function reabrir(Request $request, string $mes): JsonResponse
    {
        $periodo = $this->respuesta->periodo(['mes' => $mes], obligatorio: true);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [
            'motivo.required' => 'Indique por qué se reabre el mes.',
            'motivo.min' => 'Explique el motivo en al menos 5 caracteres.',
            'motivo.max' => 'El motivo no puede superar 500 caracteres.',
        ]);

        $this->gestor->reabrir($periodo->clave(), trim($datos['motivo']), $request->user());

        return response()->json($this->respuesta->mes($request, $periodo));
    }
}
