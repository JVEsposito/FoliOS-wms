<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\TipoEventoPrefrio;
use App\Http\Controllers\Concerns\RespondeConEtagOperacional;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccionProcesoPrefrioRequest;
use App\Http\Requests\AgregarFolioProcesoPrefrioRequest;
use App\Http\Requests\AprobarProcesoPrefrioRequest;
use App\Http\Requests\CancelarProcesoPrefrioRequest;
use App\Http\Requests\ConsultarProcesosPrefrioRequest;
use App\Http\Requests\CorregirProcesoPrefrioRequest;
use App\Http\Requests\CrearProcesoPrefrioRequest;
use App\Http\Requests\ReprocesarProcesoPrefrioRequest;
use App\Http\Resources\ProcesoPrefrioResource;
use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Services\Prefrio\RevisionPrefrioOperacional;
use App\Services\Prefrio\ServicioCorreccionProcesoPrefrio;
use App\Services\Prefrio\ServicioGeneracionRecepcionTunel;
use App\Services\Prefrio\ServicioProcesoPrefrio;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ProcesoPrefrioController extends Controller
{
    use RespondeConEtagOperacional;

    public function resumen(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('consultar-prefrio'), 403);

        $estadosActivos = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->all();

        return response()->json([
            'en_proceso' => ProcesoPrefrio::query()
                ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->where('estado', EstadoProcesoPrefrio::EnProceso)
                ->count(),
            'pendiente_verificacion' => ProcesoPrefrio::query()
                ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->where('estado', EstadoProcesoPrefrio::PendienteVerificacion)
                ->count(),
            'requiere_reproceso' => ProcesoPrefrio::query()
                ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->where('estado', EstadoProcesoPrefrio::RequiereReproceso)
                ->count(),
            'folios_activos' => ProcesoPrefrioFolio::query()
                ->whereHas('proceso', fn ($consulta) => $consulta
                    ->whereHas('temporada', fn ($temporada) => $temporada->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->whereIn('estado', $estadosActivos))
                ->whereNotIn('estado', [
                    EstadoFolioProcesoPrefrio::Retirado->value,
                    EstadoFolioProcesoPrefrio::Cancelado->value,
                ])
                ->count(),
        ]);
    }

    public function index(
        ConsultarProcesosPrefrioRequest $request,
        RevisionPrefrioOperacional $revision,
    ): Response {
        $datos = $request->validated();
        $soloActivos = (bool) ($datos['solo_activos'] ?? false);
        $folio = mb_strtoupper(trim((string) ($datos['folio'] ?? '')));
        $pagina = max(1, $request->integer('page', 1));
        $porPagina = (int) ($datos['per_page'] ?? 25);

        $etag = 'prefrio-procesos-'.$revision->procesos([
            'solo_activos' => $soloActivos,
            'tunel_prefrio_id' => $datos['tunel_prefrio_id'] ?? null,
            'estado' => $datos['estado'] ?? null,
            'folio' => $folio !== '' ? $folio : null,
            'fecha_desde' => $datos['fecha_desde'] ?? null,
            'fecha_hasta' => $datos['fecha_hasta'] ?? null,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
        ]);
        $respuestaCondicional = $this->conEtagOperacional(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        $estadosActivos = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->all();
        $procesos = ProcesoPrefrio::query()
            ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->when($soloActivos, fn ($consulta) => $consulta
                ->whereIn('estado', $estadosActivos))
            ->when($datos['tunel_prefrio_id'] ?? null, fn ($consulta, string $id) => $consulta
                ->where('tunel_prefrio_id', $id))
            ->when($datos['estado'] ?? null, fn ($consulta, string $estado) => $consulta
                ->where('estado', $estado))
            ->when($folio !== '', fn ($consulta) => $consulta
                ->whereHas('folios.folio', fn ($folios) => $folios
                    ->where('numero_folio', 'like', "%{$folio}%")))
            ->when($datos['fecha_desde'] ?? null, fn ($consulta, string $fecha) => $consulta
                ->whereDate('created_at', '>=', $fecha))
            ->when($datos['fecha_hasta'] ?? null, fn ($consulta, string $fecha) => $consulta
                ->whereDate('created_at', '<=', $fecha))
            ->latest('created_at')
            ->paginate($porPagina);

        $procesos->getCollection()->load($this->relaciones());

        return $this->conEtagOperacional(
            ProcesoPrefrioResource::collection($procesos)->response(),
            $etag,
        );
    }

    public function show(Request $request, ProcesoPrefrio $procesoPrefrio): ProcesoPrefrioResource
    {
        abort_unless($request->user()?->can('consultar-prefrio'), 403);

        return new ProcesoPrefrioResource($procesoPrefrio->load($this->relaciones()));
    }

    public function store(
        CrearProcesoPrefrioRequest $request,
        ServicioProcesoPrefrio $servicio,
    ): Response {
        $proceso = $servicio->crear(
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        );

        return (new ProcesoPrefrioResource($proceso))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function agregarFolio(
        AgregarFolioProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->agregarFolio(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function retirarFolio(
        AccionProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ProcesoPrefrioFolio $asignacionPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->retirarFolio(
            $procesoPrefrio,
            $asignacionPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function confirmarArmado(
        AccionProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->confirmarArmado(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function iniciar(
        AccionProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->iniciar(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function registrarEvento(
        AccionProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        string $tipo,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        $evento = TipoEventoPrefrio::tryFrom($tipo);

        if (! $evento) {
            throw new DomainException('El tipo de evento de prefrío no existe.');
        }

        $datos = $request->validated();
        $datos = [...$datos, ...($datos['datos'] ?? [])];
        unset($datos['datos']);

        return new ProcesoPrefrioResource($servicio->registrarEventoOperacional(
            $procesoPrefrio,
            $evento,
            $datos,
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function enviarAVerificacion(
        AccionProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->enviarAVerificacion(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function aprobar(
        AprobarProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
        ServicioGeneracionRecepcionTunel $generador,
    ): ProcesoPrefrioResource {
        $dispositivo = $this->dispositivo($request);
        $proceso = DB::transaction(function () use (
            $request,
            $procesoPrefrio,
            $servicio,
            $generador,
            $dispositivo,
        ): ProcesoPrefrio {
            $aprobado = $servicio->aprobar(
                $procesoPrefrio,
                $request->validated(),
                $request->user(),
                $dispositivo,
            );
            $generador->generar($aprobado, $request->user());

            return $aprobado;
        }, attempts: 3);

        return new ProcesoPrefrioResource($proceso);
    }

    public function reprocesar(
        ReprocesarProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->requerirReproceso(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function cancelar(
        CancelarProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->cancelar(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
            $this->dispositivo($request),
        ));
    }

    public function corregir(
        CorregirProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioCorreccionProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->corregir(
            $procesoPrefrio,
            $request->validated(),
            $request->user(),
        ));
    }

    private function dispositivo(Request $request): ?Dispositivo
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->dispositivo_id === null) {
            return null;
        }

        return $token->dispositivo()->where('activo', true)->first();
    }

    /**
     * @return array<int, string|array<string, mixed>>
     */
    private function relaciones(): array
    {
        return [
            'temporada:id,codigo,nombre,activa',
            'tunel:id,codigo,nombre,capacidad_posiciones,setpoint_habitual,estado_administrativo,estado_tecnico,version_configuracion',
            'folios' => fn ($consulta) => $consulta
                ->with([
                    'folio:id,numero_folio,tipo_bulto,estado_operacional,condicion_termica,habilitacion_almacenamiento,variedad,calibre,marca,exportadora,datos_externos',
                    'posicion:id,tunel_prefrio_id,numero,etiqueta,activa',
                    'cargadoPor:id,name',
                ])
                ->orderBy('created_at'),
            'eventos' => fn ($consulta) => $consulta
                ->with(['usuario:id,name', 'dispositivo:id,codigo,nombre'])
                ->latest('ocurrido_at')
                ->latest('created_at'),
            'creadoPor:id,name',
            'iniciadoPor:id,name',
            'finalizadoPor:id,name',
        ];
    }
}
