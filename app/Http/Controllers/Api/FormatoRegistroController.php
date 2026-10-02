<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarFormatoRegistroRequest;
use App\Models\FormatoRegistro;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FormatoRegistroController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('administrar-accesos');

        return response()->json(['data' => FormatoRegistro::query()
            ->with('actualizadoPor:id,name')->orderBy('codigo')->get()
            ->map(fn ($formato): array => $this->recurso($formato))]);
    }

    public function eventos(FormatoRegistro $formatoRegistro): JsonResponse
    {
        Gate::authorize('administrar-accesos');

        return response()->json(['data' => $formatoRegistro->eventos()
            ->with('usuario:id,name')->orderByDesc('created_at')->paginate(25)]);
    }

    public function store(GuardarFormatoRegistroRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->recurso($this->guardar($request))], 201);
    }

    public function update(GuardarFormatoRegistroRequest $request, FormatoRegistro $formatoRegistro): JsonResponse
    {
        return response()->json(['data' => $this->recurso($this->guardar($request, $formatoRegistro))]);
    }

    private function guardar(GuardarFormatoRegistroRequest $request, ?FormatoRegistro $formato = null): FormatoRegistro
    {
        return DB::transaction(function () use ($request, $formato): FormatoRegistro {
            $datos = $request->safe()->except('actualizado_at_conocido');
            if ($formato) {
                $formato = FormatoRegistro::query()->lockForUpdate()->findOrFail($formato->id);
                if ($datos['codigo'] !== $formato->codigo) {
                    throw new ConflictoOperacion('El código identifica el formato y no se puede cambiar. Crea otro formato para un código nuevo.');
                }
                $conocido = $request->input('actualizado_at_conocido');
                if ($conocido !== null && ! $formato->updated_at->equalTo(CarbonImmutable::parse($conocido))) {
                    throw new ConflictoOperacion('El formato cambió. Actualiza el listado antes de guardar.');
                }
            }
            $antes = $formato ? $formato->only(array_keys($datos)) : null;
            $formato ??= new FormatoRegistro;
            $formato->fill([
                ...$datos,
                'creado_por_user_id' => $formato->exists ? $formato->creado_por_user_id : $request->user()->id,
                'actualizado_por_user_id' => $request->user()->id,
            ])->save();
            $formato->eventos()->create([
                'user_id' => $request->user()->id,
                'antes' => $antes,
                'despues' => $formato->only(array_keys($datos)),
            ]);

            return $formato->load('actualizadoPor:id,name');
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function recurso(FormatoRegistro $formato): array
    {
        return [
            ...$formato->only('id', 'codigo', 'nombre', 'version', 'localidad', 'activo'),
            'fecha_vigencia' => $formato->fecha_vigencia->format('Y-m-d'),
            'actualizado_at' => $formato->updated_at->toAtomString(),
            'actualizado_por' => $formato->actualizadoPor?->name,
        ];
    }
}
