<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\EspecieValidacion;
use App\Models\EventoCatalogoFrutaEmbalada;
use App\Models\PlantaOrigen;
use App\Models\UmbralPrefrioEspecie;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogoFrutaEmbaladaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'plantas_origen' => PlantaOrigen::orderBy('nombre')->get(),
            'umbrales' => UmbralPrefrioEspecie::orderBy('especie')->get(),
            'especies' => EspecieValidacion::where('activo', true)->distinct()->orderBy('nombre')->pluck('nombre'),
        ]);
    }

    public function storePlanta(Request $request): JsonResponse
    {
        return $this->guardarPlanta($request);
    }

    public function updatePlanta(Request $request, PlantaOrigen $plantaOrigen): JsonResponse
    {
        return $this->guardarPlanta($request, $plantaOrigen);
    }

    private function guardarPlanta(Request $request, ?PlantaOrigen $planta = null): JsonResponse
    {
        foreach (['codigo', 'nombre'] as $campo) {
            if (is_string($request->input($campo))) {
                $valor = trim($request->input($campo));
                $request->merge([$campo => $campo === 'codigo' ? mb_strtoupper($valor) : $valor]);
            }
        }
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('plantas_origen', 'codigo')->ignore($planta?->id)],
            'nombre' => ['required', 'string', 'max:150'], 'activa' => ['required', 'boolean'],
            'version_conocida' => [$planta ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);

        return $this->guardar($request, $planta ?? new PlantaOrigen, $datos, 'planta');
    }

    public function storeUmbral(Request $request): JsonResponse
    {
        return $this->guardarUmbral($request);
    }

    public function updateUmbral(Request $request, UmbralPrefrioEspecie $umbralPrefrioEspecie): JsonResponse
    {
        return $this->guardarUmbral($request, $umbralPrefrioEspecie);
    }

    private function guardarUmbral(Request $request, ?UmbralPrefrioEspecie $umbral = null): JsonResponse
    {
        if (is_string($request->input('especie'))) {
            $request->merge(['especie' => mb_strtoupper(trim($request->input('especie')))]);
        }
        $datos = $request->validate([
            'especie' => ['required', 'string', 'max:150', Rule::unique('umbrales_prefrio_especies', 'especie')->ignore($umbral?->id)],
            'temperatura_maxima_c' => ['required', 'numeric', 'between:-50,80', 'decimal:0,2'],
            'version_conocida' => [$umbral ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);

        return $this->guardar($request, $umbral ?? new UmbralPrefrioEspecie, $datos, 'umbral');
    }

    private function guardar(Request $request, Model $modelo, array $datos, string $tipo): JsonResponse
    {
        $nuevo = ! $modelo->exists;
        $resultado = DB::transaction(function () use ($modelo, $datos, $request, $tipo): Model {
            if ($modelo->exists) {
                $modelo = $modelo->newQuery()->lockForUpdate()->findOrFail($modelo->id);
                if ($modelo->version !== $datos['version_conocida']) {
                    throw new ConflictoOperacion('El catálogo cambió. Actualiza antes de guardar.');
                }
            }
            $antes = $modelo->exists ? $modelo->toArray() : null;
            unset($datos['version_conocida']);
            $modelo->fill([...$datos, 'version' => $modelo->exists ? $modelo->version + 1 : 1])->save();
            EventoCatalogoFrutaEmbalada::create(['tipo' => $tipo, 'registro_id' => $modelo->id, 'user_id' => $request->user()->id, 'antes' => $antes, 'despues' => $modelo->toArray()]);

            return $modelo;
        }, attempts: 3);

        return response()->json(['data' => $resultado], $nuevo ? 201 : 200);
    }
}
