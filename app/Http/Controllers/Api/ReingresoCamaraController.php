<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OperacionNoAutorizada;
use App\Http\Controllers\Controller;
use App\Models\ReingresoFolioCamara;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Cargas\ServicioReingresoCamara;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReingresoCamaraController extends Controller
{
    public function __construct(private readonly ServicioReingresoCamara $servicio) {}

    public function index(Request $request, ServicioTemporadaActiva $temporadas): JsonResponse
    {
        $temporada = $temporadas->obtener();

        return response()->json([
            'data' => $this->servicio->bandeja($temporada->id, $request->boolean('incluir_ubicados')),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function temperatura(Request $request, ReingresoFolioCamara $reingreso, AlcanceOperacionalUsuario $alcance): JsonResponse
    {
        $usuario = $request->user();
        if (! $alcance->puedeEnviarFoliosAnden($usuario) && ! $alcance->puedeGestionarCargas($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la temperatura del reingreso.');
        }
        $datos = $request->validate([
            'temperatura_pulpa' => ['required', 'numeric', 'between:-5,40'],
        ]);
        $this->servicio->registrarTemperatura($reingreso, (float) $datos['temperatura_pulpa'], $usuario);

        return $this->fila($reingreso);
    }

    public function programar(ReingresoFolioCamara $reingreso): JsonResponse
    {
        $this->servicio->programarUbicacion($reingreso->id);

        return $this->fila($reingreso);
    }

    private function fila(ReingresoFolioCamara $reingreso): JsonResponse
    {
        $fila = collect($this->servicio->bandeja($reingreso->temporada_id, incluirUbicados: true, limite: 500))
            ->firstWhere('id', $reingreso->id);

        return response()->json(['data' => $fila]);
    }
}
