<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreguntaAsistenteRequest;
use App\Models\ConfiguracionAsistente;
use App\Models\PreguntaAsistente;
use App\Models\RangoTaraAsistente;
use App\Models\User;
use App\Services\Asistente\CompuertaAsistente;
use App\Services\Asistente\Haiku\ClienteAnthropic;
use App\Services\Asistente\Haiku\PresupuestoHaiku;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ServicioAsistente;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** API del asistente de Oficina (docs/ASISTENTE_OFICINA.md). */
class AsistenteController extends Controller
{
    public function __construct(
        private readonly ServicioAsistente $asistente,
        private readonly ClienteAnthropic $anthropic,
        private readonly PresupuestoHaiku $presupuesto,
    ) {}

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

        return response()->json(['data' => $this->asistente->ejecutar($request->user(), $clave, $this->parametros($request))]);
    }

    /** Recomendación a pedido sobre el resultado de una consulta (solo en modo mixto). */
    public function recomendar(Request $request, string $clave): JsonResponse
    {
        Gate::authorize('usar-asistente');

        return response()->json(['data' => $this->asistente->recomendar($request->user(), $clave, $this->parametros($request))]);
    }

    private function parametros(Request $request): ParametrosConsulta
    {
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
            'agrupar' => ['nullable', Rule::in(['dia'])],
        ]);

        return ParametrosConsulta::desdeArreglo($datos);
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
            'modo' => ['nullable', Rule::in(ConfiguracionAsistente::MODOS)],
            'tope_mensual_usd' => ['nullable', 'numeric', 'between:0,200'],
            'limite_diario_usuario' => ['nullable', 'integer', 'between:1,200'],
            'usuarios_piloto' => ['nullable', 'array', 'max:20'],
            'usuarios_piloto.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('activo', true)],
            'rangos' => ['present', 'array'],
            'rangos.*.tipo_envase' => ['required', 'string', Rule::in(['bins', 'totes'])],
            'rangos.*.especie' => ['nullable', 'string', 'max:100'],
            'rangos.*.minimo_kg' => ['required', 'numeric', 'gt:0'],
            'rangos.*.maximo_kg' => ['required', 'numeric', 'gt:rangos.*.minimo_kg'],
            'rangos.*.activo' => ['required', 'boolean'],
        ], [
            'rangos.*.maximo_kg.gt' => 'El máximo del rango debe ser mayor que el mínimo.',
        ]);

        if (($datos['modo'] ?? null) === ConfiguracionAsistente::MODO_MIXTO && ! $this->anthropic->disponible()) {
            throw ValidationException::withMessages(['modo' => 'Falta ANTHROPIC_API_KEY en el .env del servidor: el asistente sigue sin IA.']);
        }

        DB::transaction(function () use ($datos): void {
            $configuracion = ConfiguracionAsistente::actual();
            $configuracion->update(array_filter([
                'margen_desviaciones' => $datos['margen_desviaciones'],
                'modo' => $datos['modo'] ?? null,
            ], fn ($valor): bool => $valor !== null));
            // Los topes y el piloto se guardan solo si vienen; un tope vacío vuelve al valor del .env.
            foreach (['tope_mensual_usd', 'limite_diario_usuario', 'usuarios_piloto'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $configuracion->{$campo} = $campo === 'usuarios_piloto' ? array_values(array_map('intval', $datos[$campo] ?? [])) : $datos[$campo];
                }
            }
            $configuracion->save();
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
                'interprete' => $p->interprete,
                'usuario' => $p->usuario?->name,
                'fecha' => $p->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['pagina' => $preguntas->currentPage(), 'paginas' => $preguntas->lastPage(), 'total' => $preguntas->total()],
        ]);
    }

    /** Compuerta de la fase B: registro real y banco, con la decisión de conectar o no Haiku. */
    public function compuerta(Request $request, CompuertaAsistente $compuerta): JsonResponse
    {
        Gate::authorize('administrar-asistente');
        $dias = (int) ($request->validate(['dias' => ['nullable', 'integer', 'between:1,90']])['dias'] ?? 7);

        return response()->json(['data' => $compuerta->medir($request->user(), $dias)]);
    }

    /** Consumo de Haiku del mes, por día y por usuario. */
    public function consumo(): JsonResponse
    {
        Gate::authorize('administrar-asistente');

        return response()->json(['data' => $this->presupuesto->resumenMes()]);
    }

    /** Usuarios activos que pueden usar el asistente: los candidatos al piloto. */
    public function usuarios(AlcanceOperacionalUsuario $alcance): JsonResponse
    {
        Gate::authorize('administrar-asistente');
        $usuarios = User::query()->where('activo', true)->orderBy('name')->get()
            ->filter(fn (User $usuario): bool => $alcance->puedeUsarAsistente($usuario))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'nombre' => $usuario->name, 'rol' => $usuario->rol?->value ?? (string) $usuario->rol])
            ->values();

        return response()->json(['data' => $usuarios]);
    }

    private function serializarConfiguracion(): array
    {
        $configuracion = ConfiguracionAsistente::actual();

        return [
            'modo' => $configuracion->modo,
            'ia' => [
                'disponible' => $this->anthropic->disponible(),
                'modelo' => $this->anthropic->modelo(),
                'consumo_mes_usd' => $this->presupuesto->consumoMes(),
                'tope_mensual_usd' => $configuracion->topeMensual(),
                'limite_diario_usuario' => $configuracion->limiteDiario(),
                'tope_mensual_propio' => $configuracion->tope_mensual_usd !== null,
                'limite_diario_propio' => $configuracion->limite_diario_usuario !== null,
                'usuarios_piloto' => array_values(array_map('intval', $configuracion->usuarios_piloto ?? [])),
            ],
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
