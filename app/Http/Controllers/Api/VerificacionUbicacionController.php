<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Models\Folio;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Temporadas\ServicioTemporadaActiva;
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
            'respuesta' => ['required', Rule::in(['folio', 'folios', 'vacia'])],
            'numero_folio' => ['required_if:respuesta,folio', 'nullable', 'string', 'max:100'],
            'folios' => ['required_if:respuesta,folios', 'array', 'max:500'],
            'folios.*.numero_folio' => ['required', 'string', 'max:100'],
            'folios.*.cantidad_contada' => ['nullable', 'numeric', 'between:0,99999999999.999'],
        ]);
        [$usuario, $dispositivo] = $contexto->obtener($request);
        [$ronda, $registrado] = $servicio->registrar(
            $item, $usuario, $dispositivo, $datos['operacion_id'],
            $datos['version'], $datos['numero_folio'] ?? null,
            $datos['folios'] ?? null, $datos['respuesta'] === 'vacia',
        );

        return response()->json([
            'data' => $this->publicar($ronda),
            'resultado' => $registrado->resultado,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function material(Request $request, ContextoOperacional $contexto): JsonResponse
    {
        [$usuario] = $contexto->obtener($request);
        abort_unless($usuario->rol === RolUsuario::CamareroMateriales, 403);
        $datos = $request->validate(['numero_folio' => ['required', 'string', 'max:100']]);
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $folio = Folio::query()->where('temporada_id', $temporada->id)
            ->where('numero_folio', mb_strtoupper(trim($datos['numero_folio'])))->with('material.item')->first();

        return response()->json(['data' => ['unidad_medida' => $folio?->material?->item?->unidad_medida]])
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function publicar(VerificacionUbicacion $ronda): array
    {
        // Contrato ciego: nunca serializar el modelo de ítem ni el folio
        // esperado, ni siquiera cuando el ítem ya se verificó.
        return [
            'id' => $ronda->id,
            'contenido' => $ronda->contenido,
            'verificar_cantidad' => $ronda->verificar_cantidad,
            'estado' => $ronda->estado,
            'version' => $ronda->version,
            'inicio_at' => $ronda->turno_inicio_at->toAtomString(),
            'vence_at' => $ronda->vence_at->toAtomString(),
            'objetivo' => $ronda->objetivo,
            'completadas' => $ronda->items->filter(fn ($i) => $i->resultado !== null && $i->resultado !== 'no_aplica')->count(),
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
