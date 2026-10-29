<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPerfilImpresionEtiquetaRequest;
use App\Models\PerfilImpresionEtiqueta;
use App\Services\Materiales\DisenoEtiquetaClasicaMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PerfilImpresionEtiquetaController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('consultar-recepciones-materiales');

        return response()->json([
            'data' => PerfilImpresionEtiqueta::query()
                ->where('activo', true)
                ->orderByDesc('predeterminado')
                ->orderBy('nombre')
                ->get()
                ->map(fn (PerfilImpresionEtiqueta $perfil): array => $this->perfil($perfil)),
        ]);
    }

    public function administracion(): JsonResponse
    {
        Gate::authorize('consultar-accesos');

        return response()->json([
            'data' => PerfilImpresionEtiqueta::query()
                ->orderByDesc('activo')
                ->orderByDesc('predeterminado')
                ->orderBy('nombre')
                ->get()
                ->map(fn (PerfilImpresionEtiqueta $perfil): array => $this->perfil($perfil)),
        ]);
    }

    public function store(GuardarPerfilImpresionEtiquetaRequest $request): JsonResponse
    {
        $perfil = $this->guardar($request);

        return response()->json(
            ['data' => $this->perfil($perfil)],
            Response::HTTP_CREATED,
        );
    }

    public function update(
        GuardarPerfilImpresionEtiquetaRequest $request,
        PerfilImpresionEtiqueta $perfilImpresionEtiqueta,
    ): JsonResponse {
        return response()->json([
            'data' => $this->perfil($this->guardar($request, $perfilImpresionEtiqueta)),
        ]);
    }

    private function guardar(
        GuardarPerfilImpresionEtiquetaRequest $request,
        ?PerfilImpresionEtiqueta $perfil = null,
    ): PerfilImpresionEtiqueta {
        $datos = $request->validated();

        return DB::transaction(function () use ($datos, $request, $perfil): PerfilImpresionEtiqueta {
            if ($datos['predeterminado']) {
                PerfilImpresionEtiqueta::query()->update([
                    'predeterminado' => false,
                    'updated_at' => now(),
                ]);
            }

            $perfil ??= new PerfilImpresionEtiqueta;
            $perfil->fill([
                ...$datos,
                'predeterminado' => $datos['predeterminado'] && $datos['activo'],
                'creado_por_user_id' => $perfil->creado_por_user_id ?? $request->user()->id,
                'actualizado_por_user_id' => $request->user()->id,
            ]);
            $perfil->save();

            if (! PerfilImpresionEtiqueta::query()
                ->where('activo', true)
                ->where('predeterminado', true)
                ->exists()) {
                PerfilImpresionEtiqueta::query()
                    ->where('activo', true)
                    ->orderBy('created_at')
                    ->first()
                    ?->update(['predeterminado' => true]);
            }

            return $perfil->refresh();
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function perfil(PerfilImpresionEtiqueta $perfil): array
    {
        return [
            'id' => $perfil->id,
            'codigo' => $perfil->codigo,
            'nombre' => $perfil->nombre,
            'fabricante' => $perfil->fabricante,
            'modelo' => $perfil->modelo,
            'lenguaje' => $perfil->lenguaje,
            'dpi' => $perfil->dpi,
            'ancho_mm' => $perfil->ancho_mm,
            'alto_mm' => $perfil->alto_mm,
            'orientacion' => $perfil->orientacion,
            'predeterminado' => $perfil->predeterminado,
            'activo' => $perfil->activo,
            'disenos_disponibles' => $perfil->activo && DisenoEtiquetaClasicaMaterial::admitePerfil($perfil->only(['ancho_mm', 'alto_mm']))
                ? ['actual', DisenoEtiquetaClasicaMaterial::CODIGO] : ['actual'],
            'created_at' => $perfil->created_at?->toAtomString(),
            'updated_at' => $perfil->updated_at?->toAtomString(),
        ];
    }
}
