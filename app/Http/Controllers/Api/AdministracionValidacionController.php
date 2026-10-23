<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArticuloValidacion;
use App\Models\CombinacionValidacion;
use App\Models\ImportacionValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Services\Validacion\ServicioCatalogoValidacion;
use App\Services\Validacion\ServicioImportacionValidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdministracionValidacionController extends Controller
{
    public function index(
        Request $request,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'resumen' => ['sometimes', 'boolean'],
        ]);
        $temporada = $request->filled('temporada_id')
            ? Temporada::query()->findOrFail($request->string('temporada_id')->toString())
            : null;

        return response()->json($servicio->datosAdministracion($temporada, $request->boolean('resumen')));
    }

    public function storeArticulo(
        Request $request,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $articulo = $servicio->guardarArticulo($this->datosArticulo($request));

        return response()->json(['data' => $articulo], Response::HTTP_CREATED);
    }

    public function updateArticulo(
        Request $request,
        ArticuloValidacion $articuloValidacion,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $articulo = $servicio->guardarArticulo(
            $this->datosArticulo($request),
            $articuloValidacion,
        );

        return response()->json(['data' => $articulo]);
    }

    public function storeOrigen(
        Request $request,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $origen = $servicio->guardarOrigen($this->datosOrigen($request));

        return response()->json(['data' => $origen], Response::HTTP_CREATED);
    }

    public function updateOrigen(
        Request $request,
        OrigenValidacion $origenValidacion,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $origen = $servicio->guardarOrigen(
            $this->datosOrigen($request),
            $origenValidacion,
        );

        return response()->json(['data' => $origen]);
    }

    public function storeCombinacion(
        Request $request,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $combinacion = $servicio->guardarCombinacion($this->datosCombinacion($request));

        return response()->json(['data' => $combinacion], Response::HTTP_CREATED);
    }

    public function updateCombinacion(
        Request $request,
        CombinacionValidacion $combinacionValidacion,
        ServicioCatalogoValidacion $servicio,
    ): JsonResponse {
        $combinacion = $servicio->guardarCombinacion(
            $this->datosCombinacion($request),
            $combinacionValidacion,
        );

        return response()->json(['data' => $combinacion]);
    }

    public function previsualizarImportacion(
        Request $request,
        ServicioImportacionValidacion $servicio,
    ): JsonResponse {
        $datos = $request->validate([
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);
        $importacion = $servicio->previsualizar(
            $request->file('archivo'),
            Temporada::query()->findOrFail($datos['temporada_id']),
            $request->user(),
        );

        return response()->json(['data' => $importacion], Response::HTTP_CREATED);
    }

    public function confirmarImportacion(
        Request $request,
        ImportacionValidacion $importacionValidacion,
        ServicioImportacionValidacion $servicio,
    ): JsonResponse {
        return response()->json([
            'data' => $servicio->confirmar($importacionValidacion, $request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosArticulo(Request $request): array
    {
        return $request->validate([
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'especie' => ['required', 'string', 'max:100'],
            'variedad' => ['required', 'string', 'max:100'],
            'calibre' => ['required', 'string', 'max:50'],
            'envase' => ['required', 'string', 'max:100'],
            'codigo_externo' => ['nullable', 'string', 'max:100'],
            'activo' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosOrigen(Request $request): array
    {
        return $request->validate([
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'cliente' => ['required', 'string', 'max:150'],
            'marca' => ['required', 'string', 'max:150'],
            'csg' => ['required', 'string', 'max:50'],
            'predio' => ['nullable', 'string', 'max:150'],
            'codigo_externo' => ['nullable', 'string', 'max:100'],
            'activo' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosCombinacion(Request $request): array
    {
        return $request->validate([
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'articulo_validacion_id' => [
                'required',
                'uuid',
                Rule::exists('articulos_validacion', 'id'),
            ],
            'origen_validacion_id' => [
                'required',
                'uuid',
                Rule::exists('origenes_validacion', 'id'),
            ],
            'codigo_externo' => ['nullable', 'string', 'max:100'],
            'activo' => ['required', 'boolean'],
        ]);
    }
}
