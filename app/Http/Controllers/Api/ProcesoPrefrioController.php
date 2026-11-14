<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoProcesoPrefrio;
use App\Enums\TipoEventoPrefrio;
use App\Http\Controllers\Concerns\RespondeConEtagOperacional;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccionProcesoPrefrioRequest;
use App\Http\Requests\AgregarFolioProcesoPrefrioRequest;
use App\Http\Requests\AprobarProcesoPrefrioRequest;
use App\Http\Requests\CancelarProcesoPrefrioRequest;
use App\Http\Requests\ConfirmarRetiroParcialPrefrioRequest;
use App\Http\Requests\ConsultarProcesosPrefrioRequest;
use App\Http\Requests\CorregirProcesoPrefrioRequest;
use App\Http\Requests\CrearProcesoPrefrioRequest;
use App\Http\Requests\FinalizarParcialProcesoPrefrioRequest;
use App\Http\Requests\ReprocesarProcesoPrefrioRequest;
use App\Http\Resources\ProcesoPrefrioResource;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Models\PresenciaCargaAnden;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Services\Cargas\ServicioPlanDespachoDirecto;
use App\Services\Jornadas\CalendarioOperativo;
use App\Services\Prefrio\GeneradorRegistroPrefrioR41;
use App\Services\Prefrio\RegistroPrefrioR41;
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
                    ->whereHas('temporada', fn ($temporada) => $temporada->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id)))
                ->enTunelOperativo()
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
        $calendario = app(CalendarioOperativo::class);

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

        $procesos = ProcesoPrefrio::query()
            ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->when($soloActivos, fn ($consulta) => $consulta->ocupaTunel())
            ->when($datos['tunel_prefrio_id'] ?? null, fn ($consulta, string $id) => $consulta
                ->where('tunel_prefrio_id', $id))
            ->when($datos['estado'] ?? null, fn ($consulta, string $estado) => $consulta
                ->where('estado', $estado))
            ->when($folio !== '', fn ($consulta) => $consulta
                ->whereHas('folios.folio', fn ($folios) => $folios
                    ->where('numero_folio', 'like', "%{$folio}%")))
            ->when(isset($datos['fecha_desde']) || isset($datos['fecha_hasta']), function ($consulta) use ($datos, $calendario): void {
                $desde = $datos['fecha_desde'] ?? null;
                $hasta = $datos['fecha_hasta'] ?? null;
                $vigencia = $calendario->primeraVigencia();
                if (! $vigencia) {
                    $calendario->filtrar($consulta, 'created_at', $desde, $hasta, 'UTC');

                    return;
                }

                [$inicio, $fin] = $calendario->rango($desde, $hasta, 'UTC');
                $instante = 'CASE WHEN iniciado_at >= ? THEN iniciado_at ELSE created_at END';
                $consulta->when($inicio, fn ($q) => $q->whereRaw($instante.' >= ?', [$vigencia, $inicio]))
                    ->when($fin, fn ($q) => $q->whereRaw($instante.' < ?', [$vigencia, $fin]));
            })
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

    /**
     * Datos del POEMP R-41 que no salen de la operación del túnel. No cambian la versión del
     * proceso: no compiten con las operaciones de la tablet.
     */
    public function guardarRegistroR41(Request $request, ProcesoPrefrio $procesoPrefrio): ProcesoPrefrioResource
    {
        $datos = $request->validate([
            'temperatura_objetivo' => ['nullable', 'numeric', 'between:'.RegistroPrefrioR41::TEMPERATURA_MINIMA.','.RegistroPrefrioR41::TEMPERATURA_MAXIMA],
            'supervisor' => ['nullable', 'string', 'max:120'],
            'jefe_frio' => ['nullable', 'string', 'max:120'],
            'servicio' => ['nullable', 'string', 'max:120'],
        ], [], [
            'temperatura_objetivo' => 'la T° objetivo', 'supervisor' => 'el supervisor', 'jefe_frio' => 'el jefe de frío', 'servicio' => 'el servicio',
        ]);
        $procesoPrefrio->update(['registro_r41' => [
            ...collect(RegistroPrefrioR41::DATOS_PLANILLA)->mapWithKeys(fn (string $clave): array => [$clave => match ($clave) {
                'temperatura_objetivo' => isset($datos[$clave]) ? round((float) $datos[$clave], 1) : null,
                default => filled($datos[$clave] ?? null) ? trim((string) $datos[$clave]) : null,
            }])->all(),
            'actualizado_por_user_id' => $request->user()->id,
            'actualizado_at' => now()->toAtomString(),
        ]]);

        return new ProcesoPrefrioResource($procesoPrefrio->refresh()->load($this->relaciones()));
    }

    /** Planilla oficial POEMP R-41 del proceso, en Excel. */
    public function registroR41(ProcesoPrefrio $procesoPrefrio, GeneradorRegistroPrefrioR41 $generador): Response
    {
        $ruta = $generador->generar(RegistroPrefrioR41::detalle($procesoPrefrio));

        return response()->download($ruta, 'R-41_'.preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $procesoPrefrio->codigo).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
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
        ServicioPlanDespachoDirecto $despachoDirecto,
    ): ProcesoPrefrioResource {
        $dispositivo = $this->dispositivo($request);
        $proceso = DB::transaction(function () use (
            $request,
            $procesoPrefrio,
            $servicio,
            $generador,
            $despachoDirecto,
            $dispositivo,
        ): ProcesoPrefrio {
            $aprobado = $servicio->aprobar(
                $procesoPrefrio,
                $request->validated(),
                $request->user(),
                $dispositivo,
            );
            $this->programarSalidas($aprobado, $request, $generador, $despachoDirecto);

            return $aprobado;
        }, attempts: 3);

        return new ProcesoPrefrioResource($proceso);
    }

    public function finalizarParcial(
        FinalizarParcialProcesoPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
        ServicioGeneracionRecepcionTunel $generador,
        ServicioPlanDespachoDirecto $despachoDirecto,
    ): ProcesoPrefrioResource {
        $dispositivo = $this->dispositivo($request);
        $proceso = DB::transaction(function () use ($request, $procesoPrefrio, $servicio, $generador, $despachoDirecto, $dispositivo): ProcesoPrefrio {
            $proceso = $servicio->finalizarParcial($procesoPrefrio, $request->validated(), $request->user(), $dispositivo);
            $this->programarSalidas($proceso, $request, $generador, $despachoDirecto);

            return $proceso;
        }, attempts: 3);

        return new ProcesoPrefrioResource($proceso);
    }

    public function confirmarRetiroParcial(
        ConfirmarRetiroParcialPrefrioRequest $request,
        ProcesoPrefrio $procesoPrefrio,
        ServicioProcesoPrefrio $servicio,
    ): ProcesoPrefrioResource {
        return new ProcesoPrefrioResource($servicio->confirmarRetiroParcial(
            $procesoPrefrio, $request->validated(), $request->user(), $this->dispositivo($request),
        ));
    }

    private function programarSalidas(
        ProcesoPrefrio $proceso,
        Request $request,
        ServicioGeneracionRecepcionTunel $generador,
        ServicioPlanDespachoDirecto $despachoDirecto,
    ): void {
        $generador->generar($proceso, $request->user());
        $presencias = PresenciaCargaAnden::query()->whereNotNull('bloqueo_carga_id')
            ->whereIn('carga_id', CargaFolio::query()->select('carga_id')
                ->whereIn('folio_id', $proceso->folios()->conAprobacion()->select('folio_id')))
            ->get();
        foreach ($presencias as $presencia) {
            $despachoDirecto->sincronizar($presencia, $request->user());
        }
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
                    'retiradoPor:id,name',
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
