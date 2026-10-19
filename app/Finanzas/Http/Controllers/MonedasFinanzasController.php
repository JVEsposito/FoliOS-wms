<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Http\PresentadorTarifas;
use App\Finanzas\Http\Requests\GuardarMonedaRequest;
use App\Finanzas\Models\Moneda;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Monedas del tarifario. No se borran: una moneda inactiva deja de ofrecerse para precios nuevos. */
class MonedasFinanzasController extends Controller
{
    public function __construct(private readonly PresentadorTarifas $presentador) {}

    public function store(GuardarMonedaRequest $request): JsonResponse
    {
        $moneda = Moneda::query()->create([
            ...$request->safe()->only(['codigo', 'nombre', 'simbolo', 'decimales']),
            'activa' => true,
        ]);

        return response()->json(['data' => $this->presentador->moneda($moneda->refresh())], 201);
    }

    public function update(GuardarMonedaRequest $request, Moneda $moneda): JsonResponse
    {
        $moneda->update($request->safe()->only(['nombre', 'simbolo', 'decimales', 'activa']));

        return response()->json(['data' => $this->presentador->moneda($moneda->refresh())]);
    }
}
