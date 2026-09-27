<?php

namespace App\Http\Controllers\Api;

use App\Enums\PrioridadOperacional;
use App\Http\Controllers\Controller;
use App\Models\ManiobraOperacional;
use App\Services\Planificador\ServicioIntervencionesPlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntervencionPlanificadorController extends Controller
{
    public function pausar(
        Request $request,
        ManiobraOperacional $maniobraOperacional,
        ServicioIntervencionesPlanificador $servicio,
    ): JsonResponse {
        $datos = $this->validarIntervencion($request);
        $maniobra = $servicio->pausar(
            $maniobraOperacional,
            $request->user(),
            (int) $datos['version_maniobra'],
            $datos['motivo'],
            $datos['operacion_id'],
        );

        return response()->json([
            'data' => $this->serializarManiobra($maniobra, $servicio),
        ]);
    }

    public function reanudar(
        Request $request,
        ManiobraOperacional $maniobraOperacional,
        ServicioIntervencionesPlanificador $servicio,
    ): JsonResponse {
        $datos = $this->validarIntervencion($request);
        $maniobra = $servicio->reanudar(
            $maniobraOperacional,
            $request->user(),
            (int) $datos['version_maniobra'],
            $datos['motivo'],
            $datos['operacion_id'],
        );

        return response()->json([
            'data' => $this->serializarManiobra($maniobra, $servicio),
        ]);
    }

    public function repriorizar(
        Request $request,
        ManiobraOperacional $maniobraOperacional,
        ServicioIntervencionesPlanificador $servicio,
    ): JsonResponse {
        $datos = $request->validate([
            ...$this->reglasIntervencion(),
            'prioridad' => ['required', Rule::enum(PrioridadOperacional::class)],
        ]);
        $maniobra = $servicio->repriorizar(
            $maniobraOperacional,
            $request->user(),
            PrioridadOperacional::from($datos['prioridad']),
            (int) $datos['version_maniobra'],
            $datos['motivo'],
            $datos['operacion_id'],
        );

        return response()->json([
            'data' => $this->serializarManiobra($maniobra, $servicio),
        ]);
    }

    public function expirarReservas(
        Request $request,
        ServicioIntervencionesPlanificador $servicio,
    ): JsonResponse {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);
        $temporada = app(ServicioTemporadaActiva::class)->buscar() ?? abort(404);

        return response()->json([
            'data' => $servicio->expirarReservasVencidas(
                $request->user(),
                $temporada,
                (int) ($datos['limite'] ?? 100),
                $datos['motivo'],
                $datos['operacion_id'],
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function validarIntervencion(Request $request): array
    {
        return $request->validate($this->reglasIntervencion());
    }

    /** @return array<string, array<int, string>> */
    private function reglasIntervencion(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_maniobra' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function serializarManiobra(
        ManiobraOperacional $maniobra,
        ServicioIntervencionesPlanificador $servicio,
    ): array {
        $maniobra->unsetRelations();

        return [
            'id' => $maniobra->id,
            'estado' => $maniobra->estado->value,
            'prioridad' => $maniobra->prioridad->value,
            'version' => $maniobra->version,
            'pausada_at' => $maniobra->pausada_at?->toIso8601String(),
            'acciones_autorizadas' => $servicio->accionesAutorizadas($maniobra),
        ];
    }
}
