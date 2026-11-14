<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorregirControlAmbientalRequest;
use App\Http\Requests\RegistrarControlAmbientalRequest;
use App\Http\Resources\RegistroControlAmbientalResource;
use App\Models\Camara;
use App\Models\RegistroControlAmbiental;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\ControlAmbiental\ServicioControlAmbiental;
use App\Services\Jornadas\CalendarioOperativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ControlAmbientalController extends Controller
{
    public function estado(Request $request): JsonResponse
    {
        Gate::authorize('consultar-control-ambiental');
        $filtros = $request->validate([
            'camara_id' => [
                'nullable',
                'uuid',
                Rule::exists('camaras', 'id')->where(
                    'contenido',
                    ContenidoCamara::Productos->value,
                ),
            ],
        ]);
        $ahora = now();
        $camaras = Camara::query()
            ->where('contenido', ContenidoCamara::Productos->value)
            ->where('estado', EstadoCamara::Activa->value)
            ->when(
                isset($filtros['camara_id']),
                fn (Builder $consulta) => $consulta->whereKey($filtros['camara_id']),
            )
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre']);

        $ultimos = collect();
        if ($camaras->isNotEmpty()) {
            $ultimosTiempos = RegistroControlAmbiental::query()
                ->select('camara_id')
                ->selectRaw('MAX(capturado_at) as ultimo_capturado_at')
                ->whereIn('camara_id', $camaras->pluck('id'))
                ->where('capturado_at', '<=', $ahora)
                ->groupBy('camara_id');

            $ultimos = RegistroControlAmbiental::query()
                ->joinSub(
                    $ultimosTiempos,
                    'ultimos_controles',
                    function (JoinClause $join): void {
                        $join->on(
                            'ultimos_controles.camara_id',
                            '=',
                            'registros_control_ambiental.camara_id',
                        )->on(
                            'ultimos_controles.ultimo_capturado_at',
                            '=',
                            'registros_control_ambiental.capturado_at',
                        );
                    },
                )
                ->select('registros_control_ambiental.*')
                ->with($this->relacionesRegistro())
                ->get()
                ->keyBy('camara_id');
        }

        return response()->json([
            'frecuencia_minutos' => RegistroControlAmbiental::FRECUENCIA_MINUTOS,
            'generado_at' => $ahora->toAtomString(),
            'camaras' => $camaras->map(function (Camara $camara) use ($ultimos, $ahora): array {
                /** @var RegistroControlAmbiental|null $registro */
                $registro = $ultimos->get($camara->id);
                $estado = $registro === null
                    ? 'pendiente'
                    : ($registro->estaVigente($ahora) ? 'vigente' : 'vencido');

                return [
                    'camara' => [
                        'id' => $camara->id,
                        'codigo' => $camara->codigo,
                        'nombre' => $camara->nombre,
                    ],
                    'estado' => $estado,
                    'requiere_control' => $estado !== 'vigente',
                    'vencido_desde' => $estado === 'vencido'
                        ? $registro?->vigenteHasta()->toAtomString()
                        : null,
                    'ultimo_registro' => $registro
                        ? new RegistroControlAmbientalResource($registro)
                        : null,
                ];
            })->values(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-control-ambiental');
        $filtros = $request->validate([
            'camara_id' => [
                'nullable',
                'uuid',
                Rule::exists('camaras', 'id')->where(
                    'contenido',
                    ContenidoCamara::Productos->value,
                ),
            ],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $consulta = RegistroControlAmbiental::query()
            ->with($this->relacionesRegistro(true))
            ->when(
                isset($filtros['camara_id']),
                fn (Builder $query) => $query->where('camara_id', $filtros['camara_id']),
            )
            ->tap(fn (Builder $consulta) => app(CalendarioOperativo::class)
                ->filtrar($consulta, 'capturado_at', $filtros['desde'] ?? null, $filtros['hasta'] ?? null, 'UTC'))
            ->orderByDesc('capturado_at')
            ->orderByDesc('recibido_servidor_at');

        return RegistroControlAmbientalResource::collection(
            $consulta->paginate($filtros['per_page'] ?? 50)->withQueryString(),
        );
    }

    public function store(
        RegistrarControlAmbientalRequest $request,
        Camara $camara,
        ContextoOperacional $contexto,
        ServicioControlAmbiental $servicio,
    ): Response {
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $resultado = $servicio->registrar(
            $camara,
            $request->validated(),
            $usuario,
            $dispositivo,
        );

        return (new RegistroControlAmbientalResource($resultado['registro']))
            ->response()
            ->setStatusCode($resultado['creado'] ? 201 : 200);
    }

    public function corregir(
        CorregirControlAmbientalRequest $request,
        RegistroControlAmbiental $registro,
        ServicioControlAmbiental $servicio,
    ): RegistroControlAmbientalResource {
        return new RegistroControlAmbientalResource($servicio->corregir(
            $registro,
            $request->validated(),
            $request->user(),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function relacionesRegistro(bool $conCorrecciones = false): array
    {
        return [
            'camara:id,codigo,nombre',
            'registradoPor:id,name',
            'dispositivo:id,codigo,nombre',
            ...($conCorrecciones ? ['correcciones.corregidoPor:id,name'] : []),
        ];
    }
}
