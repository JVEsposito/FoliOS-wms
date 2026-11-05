<?php

namespace App\Http\Controllers\Api;

use App\Enums\EtapaMedicionPuntoRocio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Prefrio\GuardarMargenesPuntoRocioRequest;
use App\Http\Requests\Prefrio\GuardarPlanCadenaFrioRequest;
use App\Http\Requests\Prefrio\GuardarSalaControlRocioRequest;
use App\Http\Requests\Prefrio\RegistrarMedicionPuntoRocioRequest;
use App\Http\Requests\Prefrio\TextoPuntoRocioRequest;
use App\Models\Dispositivo;
use App\Models\MedicionPuntoRocio;
use App\Models\PersonalAccessToken;
use App\Models\SalaControlRocio;
use App\Services\Prefrio\PuntoRocio\ServicioPlanCadenaFrio;
use App\Services\Prefrio\PuntoRocio\ServicioPuntoRocio;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Chequeos diarios de condensación (punto de rocío) en Prefrío: oficina y tablet. */
class PuntoRocioPrefrioController extends Controller
{
    public function __construct(
        private readonly ServicioPuntoRocio $servicio,
        private readonly ServicioPlanCadenaFrio $plan,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'etapa' => ['nullable', Rule::enum(EtapaMedicionPuntoRocio::class)],
        ]);
        $fecha = isset($filtros['fecha'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filtros['fecha'], $this->servicio->zona())->startOfDay()
            : CarbonImmutable::now($this->servicio->zona())->startOfDay();
        $usuario = $request->user();

        return response()->json([
            ...$this->servicio->dia($fecha, isset($filtros['etapa']) ? EtapaMedicionPuntoRocio::from($filtros['etapa']) : null),
            'historial' => $this->servicio->historial($fecha),
            'puntos' => $this->servicio->puntos(),
            'etapas' => collect(EtapaMedicionPuntoRocio::cases())
                ->map(fn (EtapaMedicionPuntoRocio $etapa): array => ['valor' => $etapa->value, 'etiqueta' => $etapa->etiqueta()])
                ->all(),
            'referencias' => $this->servicio->referencias($fecha),
            'plan' => $this->plan->plan(),
            'parametros' => [
                // Margen de las etapas sin ajuste; cada etapa trae el suyo en margenes_por_etapa.
                'margen_minimo_c' => $this->servicio->margenPorDefecto(),
                'margenes_por_etapa' => $this->servicio->margenes(),
                'horas_referencia' => $this->servicio->horasReferencia(),
                'dias_registro_retroactivo' => (int) config('prefrio.punto_rocio.dias_registro_retroactivo', 7),
                'zona_horaria' => $this->servicio->zona(),
            ],
            'permisos' => [
                'registrar' => $usuario->can('operar-prefrio'),
                'supervisar' => $usuario->can('supervisar-prefrio'),
            ],
        ]);
    }

    public function store(RegistrarMedicionPuntoRocioRequest $request): JsonResponse
    {
        $medicion = $this->servicio->registrar($request->validated(), $request->user(), $this->dispositivo($request));

        return response()->json(['data' => $this->servicio->serializar($medicion)], $medicion->wasRecentlyCreated ? 201 : 200);
    }

    public function accion(TextoPuntoRocioRequest $request, MedicionPuntoRocio $medicion): JsonResponse
    {
        return response()->json(['data' => $this->servicio->serializar(
            $this->servicio->registrarAccion($medicion, $request->validated('texto'), $request->user()),
        )]);
    }

    public function anular(TextoPuntoRocioRequest $request, MedicionPuntoRocio $medicion): JsonResponse
    {
        return response()->json(['data' => $this->servicio->serializar(
            $this->servicio->anular($medicion, $request->validated('texto'), $request->user()),
        )]);
    }

    public function guardarSala(GuardarSalaControlRocioRequest $request, ?SalaControlRocio $sala = null): JsonResponse
    {
        $sala = $this->servicio->guardarSala($request->validated(), $request->user(), $sala);

        return response()->json(['data' => [
            'id' => $sala->id, 'nombre' => $sala->nombre, 'activa' => $sala->activa, 'version' => $sala->version,
        ]], $sala->wasRecentlyCreated ? 201 : 200);
    }

    public function guardarMargenes(GuardarMargenesPuntoRocioRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->servicio->guardarMargenes($request->validated('margenes'), $request->user())]);
    }

    public function guardarPlan(GuardarPlanCadenaFrioRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->plan->guardar($request->validated('etapas'), $request->user())]);
    }

    /** La tablet firma con su token; la oficina registra sin dispositivo. */
    private function dispositivo(Request $request): ?Dispositivo
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->dispositivo_id !== null
            ? $token->dispositivo()->where('activo', true)->first()
            : null;
    }
}
