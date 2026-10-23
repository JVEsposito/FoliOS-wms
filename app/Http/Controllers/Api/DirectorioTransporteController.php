<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConductorTransporte;
use App\Models\EmpresaTransporte;
use App\Models\RecepcionMaterial;
use App\Models\VehiculoTransporte;
use App\Rules\RutChileno;
use App\Services\Materiales\ServicioDirectorioTransporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorioTransporteController extends Controller
{
    public function conductor(Request $request): JsonResponse
    {
        $conductor = ConductorTransporte::query()->where('rut', $this->rut($request))->first();
        $ultimo = $conductor ? RecepcionMaterial::query()
            ->where('conductor_transporte_id', $conductor->id)
            ->where('estado', '!=', 'anulada')
            ->orderByDesc('created_at')->orderByDesc('id')->first() : null;

        return response()->json([
            'data' => $conductor?->only(['id', 'rut', 'nombre', 'telefono', 'version']),
            'ultimo_viaje' => $ultimo ? [
                'guia' => $ultimo->numero_guia_despacho,
                'registrado_at' => $ultimo->created_at->toAtomString(),
                'transporte' => $ultimo->transporte_snapshot,
            ] : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function empresa(Request $request): JsonResponse
    {
        return response()->json([
            'data' => EmpresaTransporte::query()->where('rut', $this->rut($request))->first()
                ?->only(['id', 'rut', 'nombre', 'telefono', 'version']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function vehiculo(Request $request): JsonResponse
    {
        $request->merge(['patente' => ServicioDirectorioTransporte::patente($request->input('patente'))]);
        $datos = $request->validate(['patente' => ['required', 'regex:/^[A-Z0-9]{4,12}$/']]);

        return response()->json([
            'data' => VehiculoTransporte::query()->where('patente', $datos['patente'])->first()
                ?->only(['id', 'patente', 'descripcion', 'version']),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function rut(Request $request): string
    {
        $request->merge(['rut' => ServicioDirectorioTransporte::rut($request->input('rut'))]);

        return $request->validate(['rut' => ['required', 'regex:/^[1-9][0-9]{5,7}-[0-9K]$/', new RutChileno]])['rut'];
    }
}
