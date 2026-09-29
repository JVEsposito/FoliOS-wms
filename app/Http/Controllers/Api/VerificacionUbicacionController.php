<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VerificacionUbicacionController extends Controller
{
    public function actual(
        Request $request,
        ContextoOperacional $contexto,
        ServicioVerificacionesUbicacion $servicio,
    ): JsonResponse {
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $ronda = $servicio->actual($usuario, $dispositivo);

        return response()->json(['data' => $ronda ? $this->publicar($ronda) : null])
            ->header('Cache-Control', 'no-store, private');
    }

    public function registrar(
        Request $request,
        VerificacionUbicacionItem $item,
        ContextoOperacional $contexto,
        ServicioVerificacionesUbicacion $servicio,
    ): JsonResponse {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'respuesta' => ['required', Rule::in(['folio', 'vacia'])],
            'numero_folio' => ['required_if:respuesta,folio', 'nullable', 'string', 'max:100'],
        ]);
        [$usuario, $dispositivo] = $contexto->obtener($request);
        [$ronda, $registrado] = $servicio->registrar(
            $item, $usuario, $dispositivo, $datos['operacion_id'],
            $datos['version'], $datos['respuesta'] === 'vacia' ? null : $datos['numero_folio'],
        );

        return response()->json([
            'data' => $this->publicar($ronda),
            'resultado' => $registrado->resultado,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function publicar(VerificacionUbicacion $ronda): array
    {
        // Contrato ciego: nunca serializar el modelo de ítem ni el folio
        // esperado, ni siquiera cuando el ítem ya se verificó.
        return [
            'id' => $ronda->id,
            'estado' => $ronda->estado,
            'version' => $ronda->version,
            'inicio_at' => $ronda->turno_inicio_at->toAtomString(),
            'vence_at' => $ronda->vence_at->toAtomString(),
            'objetivo' => $ronda->objetivo,
            'completadas' => $ronda->items->whereIn('resultado', ['coincide', 'otro_folio', 'posicion_vacia'])->count(),
            'items' => $ronda->items->filter(fn ($item) => $item->resultado !== 'no_aplica')
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'version' => $item->version,
                    'resultado' => $item->resultado,
                    'posicion' => [
                        'camara' => $item->posicion->camara->codigo,
                        'banda' => $item->posicion->banda,
                        'posicion' => $item->posicion->posicion,
                        'nivel' => $item->posicion->nivel,
                    ],
                ])->values()->all(),
        ];
    }
}
