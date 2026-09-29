<?php

namespace App\Services\Operacion;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoAdministrativoTunelPrefrio;
use App\Enums\EstadoCamara;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoIncidenciaCarga;
use App\Enums\EstadoOperacionSincronizacion;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoSesionEstiba;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\EstadoTecnicoTunelPrefrio;
use App\Models\Camara;
use App\Models\DiscrepanciaManiobra;
use App\Models\IncidenciaCargaFolio;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\OperacionSincronizacion;
use App\Models\Posicion;
use App\Models\ProcesoPrefrio;
use App\Models\RegistroControlAmbiental;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Models\VerificacionUbicacion;
use App\Services\Planificador\ServicioConciliacionPalletsHistoricos;
use App\Services\Planificador\ServicioPuestoMandoPlanificador;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use App\Services\Verificaciones\VentanasVerificacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class ServicioOperacionAhora
{
    public function __construct(
        private readonly ServicioPlanoPlanta $planos,
        private readonly ServicioPuestoMandoPlanificador $puestoMando,
        private readonly ServicioConciliacionPalletsHistoricos $conciliacion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function obtener(Temporada $temporada, bool $puedeEditarPlano = false): array
    {
        $ahora = CarbonImmutable::now();
        $horaOperacional = $ahora->setTimezone(config('app.operational_timezone'));

        $camaras = $this->camaras($ahora);
        $prefrio = $this->prefrio($temporada, $ahora);
        $ventana = app(VentanasVerificacion::class)->actual($ahora);
        app(ServicioVerificacionesUbicacion::class)->vencer($temporada);

        return [
            'generado_at' => $ahora->toAtomString(),
            'actualizacion_sugerida_segundos' => 30,
            'jornada' => [
                'fecha' => $horaOperacional->toDateString(),
                'hora' => $horaOperacional->format('H:i:s'),
                'zona_horaria' => $horaOperacional->getTimezone()->getName(),
                'turno' => $ventana['nombre'],
            ],
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ],
            'sincronizacion' => $this->sincronizacion($ahora, $horaOperacional),
            'camareros' => $this->camareros($temporada),
            'prefrio' => $prefrio,
            'incidencias' => $this->incidencias($temporada, $ahora),
            'verificaciones' => $this->verificaciones($temporada, $ventana),
            'planificador' => [
                ...$this->puestoMando->obtener($temporada),
                'conciliacion' => $this->conciliacion->diagnosticar($temporada, 0),
            ],
            'camaras' => $camaras,
            'planta' => $this->planos->obtener(
                $camaras,
                $prefrio['tuneles'],
                $puedeEditarPlano,
                $temporada,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidencias(Temporada $temporada, CarbonImmutable $ahora): array
    {
        $incidenciasCarga = IncidenciaCargaFolio::query()
            ->where('estado', EstadoIncidenciaCarga::Abierta->value)
            ->whereHas(
                'asignacion.carga',
                fn (Builder $consulta): Builder => $consulta
                    ->where('temporada_id', $temporada->id),
            )
            ->with([
                'asignacion:id,carga_id,folio_id',
                'asignacion.carga:id,temporada_id,codigo,numero_orden_externa,estado,prioridad',
                'asignacion.folio:id,numero_folio,tipo_bulto',
                'camara:id,codigo,nombre',
                'posicion:id,camara_id,etiqueta,banda,posicion,nivel',
                'reportadoPor:id,name',
                'dispositivo:id,codigo,nombre',
            ])
            ->get()
            ->map(fn (IncidenciaCargaFolio $incidencia): array => $this
                ->serializarIncidenciaCarga($incidencia, $ahora));

        $discrepanciasManiobra = DiscrepanciaManiobra::query()
            ->where('estado', EstadoDiscrepanciaManiobra::Abierta->value)
            ->whereHas(
                'maniobraOperacional.planOperacional',
                fn (Builder $consulta): Builder => $consulta
                    ->where('temporada_id', $temporada->id),
            )
            ->with([
                'folio:id,numero_folio,tipo_bulto',
                'reportadaPor:id,name',
                'dispositivo:id,codigo,nombre',
                'maniobraOperacional:id,plan_operacional_id,estado,prioridad,titulo',
                'maniobraOperacional.planOperacional:id,temporada_id,tipo,titulo',
                'tareaMovimiento:id,plan_operacional_id,maniobra_operacional_id,tipo_movimiento,tipo_paso_maniobra,estado,camara_origen_id,posicion_origen_id,camara_destino_id,posicion_destino_id',
                'tareaMovimiento.camaraOrigen:id,codigo,nombre',
                'tareaMovimiento.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
                'tareaMovimiento.camaraDestino:id,codigo,nombre',
                'tareaMovimiento.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
            ])
            ->get()
            ->map(fn (DiscrepanciaManiobra $discrepancia): array => $this
                ->serializarDiscrepanciaManiobra($discrepancia, $ahora));

        $incidenciasVerificacion = IncidenciaVerificacionUbicacion::query()
            ->where('temporada_id', $temporada->id)->where('estado', 'abierta')
            ->with(['camara:id,codigo,nombre', 'posicion:id,camara_id,etiqueta,banda,posicion,nivel',
                'folioEsperado:id,numero_folio', 'folioEncontrado:id,numero_folio',
                'otraPosicion:id,camara_id,etiqueta,banda,posicion,nivel',
                'otraPosicion.camara:id,codigo,nombre',
                'reportadaPor:id,name', 'dispositivo:id,codigo,nombre'])
            ->get()->map(fn (IncidenciaVerificacionUbicacion $incidencia): array => [
                'id' => $incidencia->id,
                'origen' => 'verificacion',
                'tipo' => $incidencia->tipo,
                'detalle' => "Ubicación {$incidencia->camara->codigo} · {$incidencia->posicion->etiqueta}: esperado "
                    .($incidencia->folioEsperado?->numero_folio ?? 'vacío').', encontrado '
                    .($incidencia->folio_encontrado_numero ?? 'vacío')
                    .($incidencia->otraPosicion ? "; figura en {$incidencia->otraPosicion->camara->codigo} · {$incidencia->otraPosicion->etiqueta}" : ''),
                'estado' => 'abierta', 'prioridad' => 'alta',
                'folio' => $incidencia->folioEsperado ? ['numero_folio' => $incidencia->folioEsperado->numero_folio] : null,
                'reportado_por' => ['id' => $incidencia->reportadaPor->id, 'nombre' => $incidencia->reportadaPor->name],
                'dispositivo' => ['id' => $incidencia->dispositivo->id, 'codigo' => $incidencia->dispositivo->codigo],
                'reportada_at' => $incidencia->reportada_at->toAtomString(),
                'antiguedad_minutos' => $this->antiguedadMinutos($incidencia->reportada_at, $ahora),
                'contexto' => ['camara' => $incidencia->camara->codigo, 'posicion' => $incidencia->posicion->etiqueta,
                    'esperado' => $incidencia->folioEsperado?->numero_folio,
                    'encontrado' => $incidencia->folio_encontrado_numero,
                    'otra_posicion' => $incidencia->otraPosicion
                        ? $incidencia->otraPosicion->camara->codigo.' · '.$incidencia->otraPosicion->etiqueta : null],
            ]);

        $abiertas = $incidenciasCarga
            ->concat($discrepanciasManiobra)
            ->concat($incidenciasVerificacion)
            ->sortByDesc('reportada_at')
            ->values();

        return [
            'resumen' => [
                'total_abiertas' => $abiertas->count(),
                'carga' => $incidenciasCarga->count(),
                'maniobra' => $discrepanciasManiobra->count(),
                'verificacion' => $incidenciasVerificacion->count(),
                'mas_antigua_at' => $abiertas->min('reportada_at'),
                'antiguedad_maxima_minutos' => $abiertas->max('antiguedad_minutos'),
            ],
            'abiertas' => $abiertas->all(),
        ];
    }

    /** @param array{inicio: CarbonImmutable, fin: CarbonImmutable, nombre: string} $ventana
     * @return array<string, mixed>
     */
    private function verificaciones(Temporada $temporada, array $ventana): array
    {
        $rondas = VerificacionUbicacion::query()->where('temporada_id', $temporada->id)
            ->where(function (Builder $q) use ($ventana): void {
                $q->where('turno_inicio_at', $ventana['inicio'])
                    ->orWhere(fn (Builder $v) => $v->where('estado', 'vencida')
                        ->where('turno_inicio_at', '>=', now()->subDay()));
            })
            ->with('items:id,verificacion_ubicacion_id,resultado')
            ->get();
        $usuarios = User::query()->whereIn('id', $rondas->pluck('user_id'))
            ->pluck('name', 'id');

        return [
            'habilitada' => (bool) config('verificaciones.habilitada'),
            'turno' => $ventana['nombre'],
            'resumen' => [
                'pendientes' => $rondas->where('estado', 'pendiente')->count(),
                'completadas' => $rondas->where('estado', 'completada')->count(),
                'vencidas' => $rondas->where('estado', 'vencida')->count(),
            ],
            'rondas' => $rondas->map(fn (VerificacionUbicacion $ronda): array => [
                'id' => $ronda->id, 'camarero' => $usuarios[$ronda->user_id] ?? 'Sin nombre',
                'estado' => $ronda->estado, 'objetivo' => $ronda->objetivo,
                'turno' => $ronda->turno_inicio_at->setTimezone(config('app.operational_timezone'))->format('H:i')
                    .'–'.$ronda->turno_fin_at->setTimezone(config('app.operational_timezone'))->format('H:i'),
                'completadas' => $ronda->items->whereIn('resultado', ['coincide', 'otro_folio', 'posicion_vacia'])->count(),
                'pendientes' => $ronda->items->whereNull('resultado')->count(),
                'vence_at' => $ronda->vence_at->toAtomString(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializarIncidenciaCarga(
        IncidenciaCargaFolio $incidencia,
        CarbonImmutable $ahora,
    ): array {
        $asignacion = $incidencia->asignacion;
        $carga = $asignacion->carga;
        $folio = $asignacion->folio;

        return [
            'id' => $incidencia->id,
            'origen' => 'carga',
            'tipo' => $incidencia->tipo->value,
            'detalle' => $incidencia->descripcion,
            'estado' => $incidencia->estado->value,
            'prioridad' => $carga->prioridad->value,
            'folio' => [
                'id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'tipo_bulto' => $folio->tipo_bulto->value,
            ],
            'reportado_por' => [
                'id' => $incidencia->reportadoPor->id,
                'nombre' => $incidencia->reportadoPor->name,
            ],
            'dispositivo' => [
                'id' => $incidencia->dispositivo->id,
                'codigo' => $incidencia->dispositivo->codigo,
                'nombre' => $incidencia->dispositivo->nombre,
            ],
            'reportada_at' => $incidencia->reportada_at->toAtomString(),
            'antiguedad_minutos' => $this->antiguedadMinutos(
                $incidencia->reportada_at,
                $ahora,
            ),
            'contexto' => [
                'carga' => [
                    'id' => $carga->id,
                    'codigo' => $carga->codigo,
                    'numero_orden_externa' => $carga->numero_orden_externa,
                    'estado' => $carga->estado->value,
                ],
                'ubicacion_reportada' => $this->extremoTarea(
                    $incidencia->camara,
                    $incidencia->posicion,
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializarDiscrepanciaManiobra(
        DiscrepanciaManiobra $discrepancia,
        CarbonImmutable $ahora,
    ): array {
        $maniobra = $discrepancia->maniobraOperacional;
        $plan = $maniobra->planOperacional;
        $tarea = $discrepancia->tareaMovimiento;
        $folio = $discrepancia->folio;

        return [
            'id' => $discrepancia->id,
            'origen' => 'maniobra',
            'tipo' => $discrepancia->tipo,
            'detalle' => $discrepancia->detalle,
            'estado' => $discrepancia->estado->value,
            'prioridad' => $maniobra->prioridad->value,
            'folio' => [
                'id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'tipo_bulto' => $folio->tipo_bulto->value,
            ],
            'reportado_por' => [
                'id' => $discrepancia->reportadaPor->id,
                'nombre' => $discrepancia->reportadaPor->name,
            ],
            'dispositivo' => [
                'id' => $discrepancia->dispositivo->id,
                'codigo' => $discrepancia->dispositivo->codigo,
                'nombre' => $discrepancia->dispositivo->nombre,
            ],
            'reportada_at' => $discrepancia->reportada_at->toAtomString(),
            'antiguedad_minutos' => $this->antiguedadMinutos(
                $discrepancia->reportada_at,
                $ahora,
            ),
            'contexto' => [
                'plan' => [
                    'id' => $plan->id,
                    'tipo' => $plan->tipo->value,
                    'titulo' => $plan->titulo,
                ],
                'maniobra' => [
                    'id' => $maniobra->id,
                    'titulo' => $maniobra->titulo,
                    'estado' => $maniobra->estado->value,
                ],
                'tarea' => [
                    'id' => $tarea->id,
                    'estado' => $tarea->estado->value,
                    'tipo_movimiento' => $tarea->tipo_movimiento->value,
                    'tipo_paso_maniobra' => $tarea->tipo_paso_maniobra?->value,
                    'origen' => $this->extremoTarea(
                        $tarea->camaraOrigen,
                        $tarea->posicionOrigen,
                    ),
                    'destino' => $this->extremoTarea(
                        $tarea->camaraDestino,
                        $tarea->posicionDestino,
                    ),
                ],
            ],
        ];
    }

    private function antiguedadMinutos(
        CarbonInterface $reportadaAt,
        CarbonImmutable $ahora,
    ): int {
        return max(0, (int) floor($reportadaAt->diffInMinutes($ahora, false)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function camareros(Temporada $temporada): array
    {
        $limiteActividad = now()->subMinutes((int) config('planificador.camarero_sesion_inactiva_minutos', 30));
        $sesiones = SesionEstiba::query()
            ->where('estado', EstadoSesionEstiba::Abierta->value)
            ->with([
                'usuario:id,name',
                'dispositivo:id,codigo,nombre',
                'camara:id,codigo,nombre,contenido',
            ])
            ->get();

        $tareasPorActor = TareaMovimiento::query()
            ->whereNotNull('responsable_user_id')
            ->whereNotNull('dispositivo_id')
            ->whereIn('estado', [
                EstadoTareaMovimiento::EnProceso->value,
                EstadoTareaMovimiento::Asumida->value,
            ])
            ->whereHas(
                'planOperacional',
                fn (Builder $consulta): Builder => $consulta
                    ->where('temporada_id', $temporada->id),
            )
            ->with([
                'responsable:id,name',
                'dispositivo:id,codigo,nombre',
                'planOperacional:id,temporada_id,tipo,titulo',
                'folio:id,numero_folio,tipo_bulto',
                'camaraOrigen:id,codigo,nombre',
                'posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
                'camaraDestino:id,codigo,nombre',
                'posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
            ])
            ->get()
            ->groupBy(fn (TareaMovimiento $tarea): string => $this->claveActor(
                $tarea->responsable_user_id,
                $tarea->dispositivo_id,
            ))
            ->map(fn (Collection $tareas): ?TareaMovimiento => $tareas
                ->sort(fn (TareaMovimiento $izquierda, TareaMovimiento $derecha): int => $this->ordenTarea($izquierda) <=> $this->ordenTarea($derecha))
                ->first());

        $sesionesPorActor = $sesiones
            ->sortBy('ultima_actividad_at')
            ->filter(fn (SesionEstiba $sesion): bool => $tareasPorActor->has($this->claveActor(
                $sesion->user_id, $sesion->dispositivo_id,
            )) || (($sesion->ultima_actividad_at ?? $sesion->iniciada_at)?->greaterThanOrEqualTo($limiteActividad) === true))
            ->keyBy(fn (SesionEstiba $sesion): string => $this->claveActor(
                $sesion->user_id, $sesion->dispositivo_id,
            ));

        return $tareasPorActor->keys()->merge($sesionesPorActor->keys())->unique()
            ->map(function (string $clave) use ($tareasPorActor, $sesionesPorActor): array {
                /** @var SesionEstiba|null $sesion */
                $sesion = $sesionesPorActor->get($clave);
                /** @var TareaMovimiento|null $tarea */
                $tarea = $tareasPorActor->get($clave);
                $usuario = $tarea?->responsable ?? $sesion?->usuario;
                $dispositivo = $tarea?->dispositivo ?? $sesion?->dispositivo;
                $ubicacion = $this->ubicacionCamarero($tarea, $sesion);

                return [
                    'usuario' => [
                        'id' => $usuario->id,
                        'nombre' => $usuario->name,
                    ],
                    'dispositivo' => [
                        'id' => $dispositivo->id,
                        'codigo' => $dispositivo->codigo,
                        'nombre' => $dispositivo->nombre,
                    ],
                    'sesion' => $sesion ? [
                        'id' => $sesion->id,
                        'estado' => $sesion->estado->value,
                        'iniciada_at' => $sesion->iniciada_at?->toAtomString(),
                        'ultima_actividad_at' => $sesion->ultima_actividad_at?->toAtomString(),
                    ] : null,
                    'ultima_actividad_at' => $tarea
                        ? ($tarea->iniciada_at ?? $tarea->asumida_at ?? $tarea->updated_at)?->toAtomString()
                        : $sesion?->ultima_actividad_at?->toAtomString(),
                    'ubicacion_actual' => $ubicacion,
                    'tarea_actual' => $tarea ? $this->serializarTarea($tarea) : null,
                ];
            })->sortBy(fn (array $actor): string => mb_strtolower($actor['usuario']['nombre'].'|'.$actor['dispositivo']['codigo']))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function ubicacionCamarero(?TareaMovimiento $tarea, ?SesionEstiba $sesion): array
    {
        if ($tarea?->estado === EstadoTareaMovimiento::EnProceso) {
            return ['tipo' => 'transito', 'camara' => null];
        }
        if ($tarea) {
            $camara = $tarea->camaraOrigen ?? $tarea->camaraDestino;

            return [
                'tipo' => $tarea->camaraOrigen ? 'origen' : 'destino_previsto',
                'camara' => $camara ? [
                    'id' => $camara->id, 'codigo' => $camara->codigo, 'nombre' => $camara->nombre,
                ] : null,
            ];
        }

        return [
            'tipo' => 'camara',
            'camara' => [
                'id' => $sesion->camara->id,
                'codigo' => $sesion->camara->codigo,
                'nombre' => $sesion->camara->nombre,
                'contenido' => $sesion->camara->contenido->value,
            ],
        ];
    }

    private function claveActor(int $usuarioId, string $dispositivoId): string
    {
        return $usuarioId.'|'.$dispositivoId;
    }

    /**
     * @return array{int, int, int, int, string}
     */
    private function ordenTarea(TareaMovimiento $tarea): array
    {
        $fecha = $tarea->iniciada_at ?? $tarea->asumida_at ?? $tarea->created_at;

        return [
            $tarea->estado === EstadoTareaMovimiento::EnProceso ? 0 : 1,
            -$tarea->prioridad->peso(),
            $fecha?->getTimestamp() ?? PHP_INT_MAX,
            $tarea->secuencia,
            $tarea->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializarTarea(TareaMovimiento $tarea): array
    {
        return [
            'id' => $tarea->id,
            'estado' => $tarea->estado->value,
            'prioridad' => $tarea->prioridad->value,
            'tipo_movimiento' => $tarea->tipo_movimiento->value,
            'instruccion' => $tarea->instruccion,
            'plan' => [
                'id' => $tarea->planOperacional->id,
                'tipo' => $tarea->planOperacional->tipo->value,
                'titulo' => $tarea->planOperacional->titulo,
            ],
            'folio' => [
                'id' => $tarea->folio->id,
                'numero_folio' => $tarea->folio->numero_folio,
                'tipo_bulto' => $tarea->folio->tipo_bulto->value,
            ],
            'origen' => $this->extremoTarea($tarea->camaraOrigen, $tarea->posicionOrigen),
            'destino' => $this->extremoTarea($tarea->camaraDestino, $tarea->posicionDestino),
            'destino_logico' => $this->destinoLogicoTarea($tarea),
            'asumida_at' => $tarea->asumida_at?->toAtomString(),
            'iniciada_at' => $tarea->iniciada_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extremoTarea(?Camara $camara, ?Posicion $posicion): ?array
    {
        if ($camara === null) {
            return null;
        }

        return [
            'camara' => [
                'id' => $camara->id,
                'codigo' => $camara->codigo,
                'nombre' => $camara->nombre,
            ],
            'posicion' => $posicion ? [
                'id' => $posicion->id,
                'etiqueta' => $posicion->etiqueta,
                'banda' => $posicion->banda,
                'posicion' => $posicion->posicion,
                'nivel' => $posicion->nivel,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function destinoLogicoTarea(TareaMovimiento $tarea): ?array
    {
        $contexto = $tarea->contexto ?? [];

        if (($contexto['tipo_decision'] ?? null) !== 'retiro_directo_anden'
            || empty($contexto['anden_id'])) {
            return null;
        }

        return [
            'tipo' => 'anden',
            'id' => $contexto['anden_id'],
            'nombre' => $contexto['anden_nombre'] ?? 'Andén',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prefrio(Temporada $temporada, CarbonImmutable $ahora): array
    {
        $estadosActivos = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->all();
        $procesos = ProcesoPrefrio::query()
            ->where('temporada_id', $temporada->id)
            ->whereIn('estado', $estadosActivos)
            ->with([
                'folios' => fn (HasMany $consulta): HasMany => $consulta
                    ->whereNotIn('estado', [
                        EstadoFolioProcesoPrefrio::Retirado->value,
                        EstadoFolioProcesoPrefrio::Cancelado->value,
                    ])
                    ->with('folio:id,variedad,datos_externos'),
            ])
            ->latest('created_at')
            ->get()
            ->unique('tunel_prefrio_id')
            ->keyBy('tunel_prefrio_id');
        // Un proceso activo de otra temporada sigue ocupando el túnel: el
        // prefrío no admite un segundo proceso aunque la vista lo filtre.
        $procesosOtraTemporada = ProcesoPrefrio::query()
            ->where('temporada_id', '!=', $temporada->id)
            ->whereIn('estado', $estadosActivos)
            ->with([
                'temporada:id,codigo',
                'folios' => fn (HasMany $consulta): HasMany => $consulta
                    ->whereNotIn('estado', [
                        EstadoFolioProcesoPrefrio::Retirado->value,
                        EstadoFolioProcesoPrefrio::Cancelado->value,
                    ]),
            ])
            ->latest('created_at')
            ->get(['id', 'codigo', 'tunel_prefrio_id', 'temporada_id', 'created_at'])
            ->unique('tunel_prefrio_id')
            ->keyBy('tunel_prefrio_id');
        $tuneles = TunelPrefrio::query()
            ->withCount([
                'posiciones as posiciones_activas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('activa', true),
            ])
            ->orderBy('codigo')
            ->get()
            ->map(function (TunelPrefrio $tunel) use ($procesos, $procesosOtraTemporada, $ahora): array {
                /** @var ProcesoPrefrio|null $proceso */
                $proceso = $procesos->get($tunel->id);
                /** @var ProcesoPrefrio|null $procesoAjeno */
                $procesoAjeno = $proceso === null ? $procesosOtraTemporada->get($tunel->id) : null;
                $operable = $tunel->estado_administrativo === EstadoAdministrativoTunelPrefrio::Activo
                    && $tunel->estado_tecnico === EstadoTecnicoTunelPrefrio::Operativo;
                $capacidad = (int) $tunel->posiciones_activas_count;
                // El registro mantiene estas posiciones asignadas hasta que
                // el proceso anterior se cierre o regularice. No confirma
                // dónde se encuentra físicamente la fruta.
                $folios = $proceso?->folios ?? $procesoAjeno?->folios ?? collect();
                $ocupadas = min(
                    $capacidad,
                    $folios->pluck('posicion_tunel_prefrio_id')->unique()->count(),
                );
                $admiteCarga = $operable && $procesoAjeno === null && ($proceso === null || in_array($proceso->estado, [
                    EstadoProcesoPrefrio::Borrador,
                    EstadoProcesoPrefrio::Cargando,
                    EstadoProcesoPrefrio::ListoParaIniciar,
                ], true));

                return [
                    'id' => $tunel->id,
                    'codigo' => $tunel->codigo,
                    'nombre' => $tunel->nombre,
                    'estado_administrativo' => $tunel->estado_administrativo->value,
                    'estado_tecnico' => $tunel->estado_tecnico->value,
                    'estado_operacional' => $procesoAjeno && $operable
                        ? 'bloqueado_otra_temporada'
                        : $this->estadoOperacionalTunel($tunel, $proceso),
                    'operable' => $operable,
                    'capacidad_posiciones' => $capacidad,
                    'posiciones_ocupadas' => $ocupadas,
                    'posiciones_sin_cerrar' => $procesoAjeno ? $ocupadas : 0,
                    'posiciones_disponibles' => $admiteCarga
                        ? max(0, $capacidad - $ocupadas)
                        : 0,
                    'ocupacion_porcentaje' => $this->porcentaje($ocupadas, $capacidad),
                    'proceso_activo' => $proceso
                        ? $this->serializarProcesoPrefrio($proceso, $ahora, $ocupadas)
                        : null,
                    'proceso_otra_temporada' => $procesoAjeno ? [
                        'id' => $procesoAjeno->id,
                        'codigo' => $procesoAjeno->codigo,
                        'temporada_codigo' => $procesoAjeno->temporada?->codigo,
                    ] : null,
                ];
            });
        $tunelesOperables = $tuneles->where('operable', true);
        $capacidad = (int) $tunelesOperables->sum('capacidad_posiciones');
        $ocupadas = (int) $tunelesOperables->sum('posiciones_ocupadas');

        return [
            'resumen' => [
                'tuneles_totales' => $tuneles->count(),
                'tuneles_operables' => $tunelesOperables->count(),
                'tuneles_disponibles' => $tuneles
                    ->where('estado_operacional', 'disponible')
                    ->count(),
                'tuneles_bloqueados_otra_temporada' => $tuneles
                    ->where('estado_operacional', 'bloqueado_otra_temporada')
                    ->count(),
                'procesos_activos' => $procesos->count(),
                'procesos_fuera_objetivo' => $tuneles
                    ->filter(fn (array $tunel): bool => (bool) (
                        $tunel['proceso_activo']['objetivo_excedido'] ?? false
                    ))
                    ->count(),
                'folios_en_tunel' => $procesos->sum(fn (ProcesoPrefrio $proceso): int => $proceso->folios->count())
                    + $procesosOtraTemporada->sum(fn (ProcesoPrefrio $proceso): int => $proceso->folios->count()),
                'folios_sin_cerrar' => $procesosOtraTemporada->sum(fn (ProcesoPrefrio $proceso): int => $proceso->folios->count()),
                'capacidad_operativa' => $capacidad,
                'posiciones_ocupadas' => $ocupadas,
                'posiciones_sin_cerrar' => (int) $tunelesOperables->sum('posiciones_sin_cerrar'),
                'ocupacion_porcentaje' => $this->porcentaje($ocupadas, $capacidad),
            ],
            'tuneles' => $tuneles->values()->all(),
        ];
    }

    private function estadoOperacionalTunel(
        TunelPrefrio $tunel,
        ?ProcesoPrefrio $proceso,
    ): string {
        if ($tunel->estado_administrativo !== EstadoAdministrativoTunelPrefrio::Activo) {
            return EstadoAdministrativoTunelPrefrio::Inactivo->value;
        }

        if ($tunel->estado_tecnico !== EstadoTecnicoTunelPrefrio::Operativo) {
            return $tunel->estado_tecnico->value;
        }

        return $proceso?->estado->value ?? 'disponible';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializarProcesoPrefrio(
        ProcesoPrefrio $proceso,
        CarbonImmutable $ahora,
        int $posicionesOcupadas,
    ): array {
        $inicio = $proceso->iniciado_at?->toImmutable();
        $corte = $proceso->estado === EstadoProcesoPrefrio::PendienteVerificacion
            ? $proceso->pendiente_verificacion_at?->toImmutable()
            : $ahora;
        $transcurridos = $inicio && $corte
            ? max(0, (int) floor($inicio->diffInMinutes($corte, false)))
            : null;
        $objetivo = $proceso->duracion_objetivo_minutos;
        $objetivoExcedido = $transcurridos !== null
            && $objetivo !== null
            && $transcurridos > $objetivo;

        return [
            'id' => $proceso->id,
            'codigo' => $proceso->codigo,
            'estado' => $proceso->estado->value,
            'setpoint_c' => $proceso->setpoint !== null ? (float) $proceso->setpoint : null,
            'formato_referencia' => $proceso->formato_referencia,
            'productos' => $this->productosProcesoPrefrio($proceso),
            'folios_cargados' => $proceso->folios->count(),
            'posiciones_ocupadas' => $posicionesOcupadas,
            'iniciado_at' => $inicio?->toAtomString(),
            'pendiente_verificacion_at' => $proceso->pendiente_verificacion_at?->toAtomString(),
            'duracion_objetivo_minutos' => $objetivo,
            'transcurridos_minutos' => $transcurridos,
            'fin_objetivo_at' => $inicio && $objetivo !== null
                ? $inicio->addMinutes($objetivo)->toAtomString()
                : null,
            'avance_tiempo_objetivo_porcentaje' => $transcurridos !== null && $objetivo !== null
                ? min(100, $this->porcentaje($transcurridos, $objetivo))
                : null,
            'objetivo_excedido' => $objetivoExcedido,
            'minutos_sobre_objetivo' => $transcurridos !== null && $objetivo !== null
                ? max(0, $transcurridos - $objetivo)
                : null,
        ];
    }

    /**
     * @return array<int, array{especie: ?string, variedad: ?string, etiqueta: string, folios: int}>
     */
    private function productosProcesoPrefrio(ProcesoPrefrio $proceso): array
    {
        return $proceso->folios
            ->map(function ($asignacion): ?array {
                $folio = $asignacion->folio;
                if ($folio === null) {
                    return null;
                }

                $datosExternos = is_array($folio->datos_externos)
                    ? $folio->datos_externos
                    : [];
                $especie = trim((string) ($datosExternos['especie'] ?? '')) ?: null;
                $variedad = trim((string) ($folio->variedad ?? '')) ?: null;
                $etiqueta = collect([$especie, $variedad])->filter()->implode(' ');

                return $etiqueta !== '' ? [
                    'especie' => $especie,
                    'variedad' => $variedad,
                    'etiqueta' => $etiqueta,
                ] : null;
            })
            ->filter()
            ->groupBy(fn (array $producto): string => mb_strtolower($producto['etiqueta']))
            ->map(function (Collection $productos): array {
                $producto = $productos->first();

                return [
                    ...$producto,
                    'folios' => $productos->count(),
                ];
            })
            ->sortBy(fn (array $producto): string => mb_strtolower($producto['etiqueta']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sincronizacion(
        CarbonImmutable $ahora,
        CarbonImmutable $horaOperacional,
    ): array {
        $ultima = OperacionSincronizacion::query()
            ->with(['usuario:id,name', 'dispositivo:id,codigo,nombre'])
            ->where('recibida_servidor_at', '<=', $ahora)
            ->orderByDesc('recibida_servidor_at')
            ->orderByDesc('id')
            ->first();
        $conteos = OperacionSincronizacion::query()
            ->where('recibida_servidor_at', '>=', $horaOperacional->startOfDay()->utc())
            ->where('recibida_servidor_at', '<=', $ahora)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'estado' => $ultima?->estado->value ?? 'sin_actividad',
            'ultima_operacion' => $ultima ? [
                'id' => $ultima->id,
                'tipo' => $ultima->tipo,
                'estado' => $ultima->estado->value,
                'recibida_servidor_at' => $ultima->recibida_servidor_at?->toAtomString(),
                'procesada_at' => $ultima->procesada_at?->toAtomString(),
                'usuario' => $ultima->usuario ? [
                    'id' => $ultima->usuario->id,
                    'nombre' => $ultima->usuario->name,
                ] : null,
                'dispositivo' => $ultima->dispositivo ? [
                    'id' => $ultima->dispositivo->id,
                    'codigo' => $ultima->dispositivo->codigo,
                    'nombre' => $ultima->dispositivo->nombre,
                ] : null,
            ] : null,
            'operaciones_hoy' => collect(EstadoOperacionSincronizacion::cases())
                ->mapWithKeys(fn (EstadoOperacionSincronizacion $estado): array => [
                    $estado->value => (int) $conteos->get($estado->value, 0),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function camaras(CarbonImmutable $ahora): array
    {
        $camaras = Camara::query()
            ->where('estado', EstadoCamara::Activa->value)
            ->withCount([
                'posiciones as posiciones_operativas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->whereColumn('banda', '<=', 'camaras.cantidad_bandas')
                    ->whereColumn('posicion', '<=', 'camaras.posiciones_por_banda')
                    ->whereColumn('nivel', '<=', 'camaras.cantidad_niveles'),
                'posiciones as posiciones_ocupadas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->whereHas('ubicacionActual')
                    ->whereColumn('banda', '<=', 'camaras.cantidad_bandas')
                    ->whereColumn('posicion', '<=', 'camaras.posiciones_por_banda')
                    ->whereColumn('nivel', '<=', 'camaras.cantidad_niveles'),
            ])
            ->orderBy('codigo')
            ->get();
        $controles = $this->ultimosControlesAmbientales(
            $camaras
                ->filter(fn (Camara $camara): bool => $camara->contenido === ContenidoCamara::Productos)
                ->pluck('id'),
            $ahora,
        );

        return $camaras->map(function (Camara $camara) use ($controles, $ahora): array {
            $capacidad = (int) $camara->posiciones_operativas_count;
            $ocupadas = min($capacidad, (int) $camara->posiciones_ocupadas_count);
            $ocupacion = $this->porcentaje($ocupadas, $capacidad);
            $nivel = $ocupacion > 90
                ? 'critica'
                : ($ocupacion >= 70 ? 'advertencia' : 'normal');
            /** @var RegistroControlAmbiental|null $control */
            $control = $controles->get($camara->id);
            $estadoControl = $camara->contenido !== ContenidoCamara::Productos
                ? null
                : ($control === null
                    ? 'pendiente'
                    : ($control->estaVigente($ahora) ? 'vigente' : 'vencido'));
            $alertas = [];

            if ($nivel !== 'normal') {
                $alertas[] = $nivel === 'critica' ? 'ocupacion_critica' : 'ocupacion_alta';
            }
            if (in_array($estadoControl, ['pendiente', 'vencido'], true)) {
                $alertas[] = 'control_ambiental_'.$estadoControl;
            }

            return [
                'id' => $camara->id,
                'codigo' => $camara->codigo,
                'nombre' => $camara->nombre,
                'contenido' => $camara->contenido->value,
                'capacidad_operativa' => $capacidad,
                'ocupadas' => $ocupadas,
                'disponibles' => max(0, $capacidad - $ocupadas),
                'ocupacion_porcentaje' => $ocupacion,
                'nivel_ocupacion' => $nivel,
                'alertas' => $alertas,
                'control_ambiental' => $estadoControl !== null ? [
                    'estado' => $estadoControl,
                    'requiere_control' => $estadoControl !== 'vigente',
                    'capturado_at' => $control?->capturado_at?->toAtomString(),
                    'vigente_hasta' => $control?->vigenteHasta()->toAtomString(),
                    'temperaturas_c' => $control ? [
                        'inicio' => (float) $control->temperatura_inicio_c,
                        'medio' => (float) $control->temperatura_medio_c,
                        'fondo' => (float) $control->temperatura_fondo_c,
                        'promedio' => round(((float) $control->temperatura_inicio_c
                            + (float) $control->temperatura_medio_c
                            + (float) $control->temperatura_fondo_c) / 3, 2),
                    ] : null,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, string>  $camaras
     * @return Collection<string, RegistroControlAmbiental>
     */
    private function ultimosControlesAmbientales(
        Collection $camaras,
        CarbonImmutable $ahora,
    ): Collection {
        if ($camaras->isEmpty()) {
            return collect();
        }

        $ultimosTiempos = RegistroControlAmbiental::query()
            ->select('camara_id')
            ->selectRaw('MAX(capturado_at) as ultimo_capturado_at')
            ->whereIn('camara_id', $camaras)
            ->where('capturado_at', '<=', $ahora)
            ->groupBy('camara_id');

        return RegistroControlAmbiental::query()
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
            ->get()
            ->keyBy('camara_id');
    }

    private function porcentaje(int $cantidad, int $total): float
    {
        return $total > 0 ? round(($cantidad / $total) * 100, 1) : 0.0;
    }
}
