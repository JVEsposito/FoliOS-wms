<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarCondicionTermicaPtRequest;
use App\Http\Requests\CambiarProductoPtRequest;
use App\Models\Folio;
use App\Models\ValidacionPallet;
use App\Services\Folios\ServicioCambioAtributosPt;
use App\Services\Validacion\ContextoCorreccionValidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CambioAtributosPtController extends Controller
{
    public function buscar(Request $request, ServicioCambioAtributosPt $servicio): JsonResponse
    {
        $datos = $request->validate(['folio' => ['required', 'string', 'max:50']]);

        return $this->ficha($servicio, $servicio->buscar($datos['folio']));
    }

    public function show(Folio $folio, ServicioCambioAtributosPt $servicio): JsonResponse
    {
        return $this->ficha($servicio, $folio);
    }

    /** Datos y catálogo del diálogo de producto, el mismo de la corrección de Validación. */
    public function contextoProducto(Request $request, Folio $folio, ServicioCambioAtributosPt $servicio, ContextoCorreccionValidacion $contexto): JsonResponse
    {
        $request->validate(['cliente' => ['nullable', 'string', 'max:150']]);
        $ficha = $servicio->ficha($folio);
        if (! $ficha['puede_cambiar_producto']) {
            throw new ConflictoOperacion($ficha['motivo_bloqueo'] ?? 'El folio no tiene una validación aprobada que corregir.');
        }

        return response()->json($contexto->construir(
            ValidacionPallet::query()->findOrFail($ficha['validacion_id']),
            $request->string('cliente')->trim()->toString() ?: null,
        ))->header('Cache-Control', 'no-store, private');
    }

    public function cambiarProducto(CambiarProductoPtRequest $request, Folio $folio, ServicioCambioAtributosPt $servicio): JsonResponse
    {
        $cambio = $servicio->cambiarProducto($folio, $request->validated(), $request->user());

        return $this->ficha($servicio, $cambio->folio()->firstOrFail(), 'Producto del folio cambiado y auditado.');
    }

    public function cambiarCondicionTermica(CambiarCondicionTermicaPtRequest $request, Folio $folio, ServicioCambioAtributosPt $servicio): JsonResponse
    {
        $cambio = $servicio->cambiarCondicionTermica($folio, $request->validated(), $request->user());

        return $this->ficha($servicio, $cambio->folio()->firstOrFail(), 'Condición de prefrío cambiada y auditada.');
    }

    private function ficha(ServicioCambioAtributosPt $servicio, Folio $folio, ?string $mensaje = null): JsonResponse
    {
        return response()->json(['data' => $servicio->ficha($folio), 'message' => $mensaje])
            ->header('Cache-Control', 'no-store, private');
    }
}
