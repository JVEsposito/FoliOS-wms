<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreguntaAsistenteRequest;
use App\Models\ConfiguracionAsistente;
use App\Models\PreguntaAsistente;
use App\Models\RangoTaraAsistente;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ServicioAsistente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** API del asistente de Oficina (docs/ASISTENTE_OFICINA.md). */
class AsistenteController extends Controller
{
    public function __construct(private readonly ServicioAsistente $asistente) {}

    public function preguntar(PreguntaAsistenteRequest $request): JsonResponse
    {
        Gate::authorize('usar-asistente');

        return response()->json(['data' => $this->asistente->preguntar($request->user(), $request->validated('texto'))]);
    }

    public function consultas(Request $request): JsonResponse
    {
        Gate::authorize('usar-asistente');

        return response()->json(['data' => $this->asistente->catalogo($request->user())]);
    }

    public function ejecutar(Request $request, string $clave): JsonResponse
    {
        Gate::authorize('usar-asistente');
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'cliente_id' => ['nullable', 'uuid'],
            'csg_id' => ['nullable', 'uuid'],
            'especie_id' => ['nullable', 'uuid'],
            'codigo' => ['nullable', 'string', 'max:60'],
            'temporada_anterior' => ['nullable', 'boolean'],
            'turno' => ['nullable', Rule::in(['A', 'B'])],
            'periodo' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(['data' => $this->asistente->ejecutar($request->user(), $clave, ParametrosConsulta::desdeArreglo($datos))]);
    }

    public function configuracion(): JsonResponse
    {
        Gate::authorize('administrar-asistente');

        return response()->json(['data' => $this->serializarConfiguracion()]);
    }

    public function actualizarConfiguracion(Request $request): JsonResponse
    {
        Gate::authorize('administrar-asistente');
        $datos = $request->validate([
            'margen_desviaciones' => ['required', 'numeric', 'between:0.5,5'],
            'rangos' => ['present', 'array'],
            'rangos.*.tipo_envase' => ['required', 'string', Rule::in(['bins', 'totes'])],
            'rangos.*.especie' => ['nullable', 'string', 'max:100'],
            'rangos.*.minimo_kg' => ['required', 'numeric', 'gt:0'],
            'rangos.*.maximo_kg' => ['required', 'numeric', 'gt:rangos.*.minimo_kg'],
            'rangos.*.activo' => ['required', 'boolean'],
        ], [
            'rangos.*.maximo_kg.gt' => 'El máximo del rango debe ser mayor que el mínimo.',
        ]);

        DB::transaction(function () use ($datos): void {
            ConfiguracionAsistente::actual()->update(['margen_desviaciones' => $datos['margen_desviaciones']]);
            foreach ($datos['rangos'] as $rango) {
                $especie = filled($rango['especie'] ?? null) ? trim($rango['especie']) : null;
                RangoTaraAsistente::query()->updateOrCreate(
                    ['tipo_envase' => $rango['tipo_envase'], 'especie' => $especie],
                    ['minimo_kg' => $rango['minimo_kg'], 'maximo_kg' => $rango['maximo_kg'], 'activo' => $rango['activo']],
                );
            }
        });

        return response()->json(['data' => $this->serializarConfiguracion()]);
    }

    public function preguntas(Request $request): JsonResponse
    {
        Gate::authorize('administrar-asistente');
        $estado = $request->validate(['estado' => ['nullable', Rule::in(['respondida', 'sin_datos', 'sugerencias'])]])['estado'] ?? null;

        $preguntas = PreguntaAsistente::query()
            ->when($estado, fn ($q, $valor) => $q->where('estado', $valor))
            ->with('usuario:id,name')
            ->latest('created_at')
            ->paginate(50);

        return response()->json([
            'data' => $preguntas->getCollection()->map(fn (PreguntaAsistente $p): array => [
                'id' => $p->id,
                'texto' => $p->texto,
                'estado' => $p->estado,
                'consulta' => $p->consulta,
                'usuario' => $p->usuario?->name,
                'fecha' => $p->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['pagina' => $preguntas->currentPage(), 'paginas' => $preguntas->lastPage(), 'total' => $preguntas->total()],
        ]);
    }

    private function serializarConfiguracion(): array
    {
        $configuracion = ConfiguracionAsistente::actual();

        return [
            'modo' => $configuracion->modo,
            'margen_desviaciones' => $configuracion->margen_desviaciones,
            'rangos' => RangoTaraAsistente::query()->orderBy('tipo_envase')->orderBy('especie')->get()
                ->map(fn (RangoTaraAsistente $r): array => [
                    'tipo_envase' => $r->tipo_envase,
                    'especie' => $r->especie,
                    'minimo_kg' => $r->minimo_kg,
                    'maximo_kg' => $r->maximo_kg,
                    'activo' => $r->activo,
                ])->values(),
        ];
    }
}
