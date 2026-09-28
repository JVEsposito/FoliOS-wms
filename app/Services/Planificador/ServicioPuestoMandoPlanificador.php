<?php

namespace App\Services\Planificador;

use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Models\Camara;
use App\Models\CicloArbitrajeManiobras;
use App\Models\DecisionArbitrajeManiobra as DecisionPersistida;
use App\Models\ManiobraOperacional;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use Illuminate\Support\Collection;

final class ServicioPuestoMandoPlanificador
{
    public function __construct(
        private readonly ServicioDesplieguePlanificador $despliegue,
        private readonly ServicioEstadoArbitrajePlanificador $estadoArbitraje,
        private readonly ServicioSaludPlanificador $salud,
        private readonly ServicioSaludProcesos $procesos,
        private readonly ServicioIntervencionesPlanificador $intervenciones,
    ) {}

    /** @return array<string, mixed> */
    public function obtener(Temporada $temporada): array
    {
        $modo = $this->despliegue->modoGlobal();
        $proyeccion = $this->estadoArbitraje->consultar($temporada);
        $ciclo = $proyeccion['ciclo'];
        unset($proyeccion['ciclo']);

        if ($ciclo) {
            $this->cargarDecisiones($ciclo);
        }

        return [
            'generado_at' => now()->toIso8601String(),
            'despliegue' => $this->despliegue->configuracion(),
            'salud' => $this->salud->saludActual($temporada->id),
            'procesos' => $this->procesos->consultar(),
            'arbitraje' => [
                'activo' => in_array($modo, ['shadow', 'guided'], true),
                'vigencia' => $proyeccion,
                'ciclo' => $ciclo ? $this->serializarCiclo($ciclo) : null,
            ],
        ];
    }

    private function cargarDecisiones(CicloArbitrajeManiobras $ciclo): void
    {
        $ciclo->load([
            'decisiones.maniobraOperacional' => fn ($consulta) => $consulta->with([
                'planOperacional:id,tipo,titulo',
                'responsable:id,name',
                'dispositivo:id,codigo,nombre',
                'pasos.folio:id,numero_folio',
                'pasos.camaraOrigen:id,codigo,nombre',
                'pasos.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
                'pasos.camaraDestino:id,codigo,nombre',
                'pasos.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
                'pasos.reservaActiva:id,bloqueo_tarea_id,estado,vence_at',
                'custodiasTemporales' => fn ($consulta) => $consulta->where(
                    'estado',
                    EstadoCustodiaTemporal::Activa->value,
                ),
                'discrepancias' => fn ($consulta) => $consulta->where(
                    'estado',
                    EstadoDiscrepanciaManiobra::Abierta->value,
                ),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializarCiclo(CicloArbitrajeManiobras $ciclo): array
    {
        $decisiones = $ciclo->decisiones
            ->filter(fn (DecisionPersistida $decision): bool => $decision->maniobraOperacional !== null)
            ->values();
        $resumen = collect(DecisionArbitrajeManiobra::cases())
            ->mapWithKeys(fn (DecisionArbitrajeManiobra $decision): array => [
                $decision->value => $decisiones
                    ->filter(fn (DecisionPersistida $persistida): bool => $persistida->decision === $decision)
                    ->count(),
            ])
            ->all();
        $resumen['en_curso'] = $decisiones->filter(fn (DecisionPersistida $decision): bool => $decision->maniobraOperacional->estado === EstadoManiobraOperacional::EnEjecucion)->count();
        $resumen['pausadas'] = $decisiones->filter(fn (DecisionPersistida $decision): bool => in_array(
            $decision->maniobraOperacional->estado,
            [EstadoManiobraOperacional::PausadaDiscrepancia, EstadoManiobraOperacional::PausadaSupervision],
            true,
        ))->count();

        return [
            'id' => $ciclo->id,
            'snapshot_version' => $ciclo->snapshot_version,
            'generado_at' => $ciclo->created_at?->toIso8601String(),
            'capacidad_ejecucion' => $ciclo->capacidad_ejecucion,
            'cupos_ocupados' => data_get($decisiones->first()?->explicacion, 'capacidad.ocupantes_fisicos')
                ?? $resumen[DecisionArbitrajeManiobra::EnEjecucion->value],
            'frontera_max' => $ciclo->frontera_max,
            'resumen' => $resumen,
            'decisiones' => $decisiones
                ->map(fn (DecisionPersistida $decision): array => $this->serializarDecision($decision))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializarDecision(DecisionPersistida $decision): array
    {
        $maniobra = $decision->maniobraOperacional;
        $pasos = $maniobra->pasos;
        $completados = $pasos
            ->filter(fn (TareaMovimiento $paso): bool => $paso->estado === EstadoTareaMovimiento::Completada)
            ->count();
        $actual = $this->pasoActual($maniobra, $pasos);

        return [
            'maniobra_id' => $maniobra->id,
            'orden' => $decision->orden,
            'decision' => $decision->decision->value,
            'estado' => $maniobra->estado->value,
            'prioridad' => $maniobra->prioridad->value,
            'version' => $maniobra->version,
            'acciones_autorizadas' => $this->intervenciones->accionesAutorizadas($maniobra),
            'titulo' => $maniobra->titulo,
            'motivo' => $decision->motivo,
            'puntaje' => $decision->puntaje,
            'beneficio_neto' => $decision->beneficio_neto,
            'costo_movimientos' => $maniobra->costo_movimientos,
            'riesgo_operacional' => $maniobra->riesgo_operacional,
            'explicacion' => $this->serializarExplicacion($decision),
            'objetivo' => [
                'tipo' => $maniobra->planOperacional?->tipo?->value,
                'titulo' => $maniobra->planOperacional?->titulo,
            ],
            'progreso' => [
                'secuencia_actual' => $maniobra->secuencia_actual,
                'pasos_total' => $pasos->count(),
                'pasos_completados' => $completados,
                'porcentaje' => $pasos->isEmpty()
                    ? 0
                    : (int) round(($completados / $pasos->count()) * 100),
            ],
            'paso_actual' => $actual ? $this->serializarPaso($actual) : null,
            'pasos' => $pasos
                ->map(fn (TareaMovimiento $paso): array => $this->serializarPaso($paso))
                ->values()
                ->all(),
            'responsable' => $maniobra->responsable ? [
                'id' => $maniobra->responsable->id,
                'nombre' => $maniobra->responsable->name,
            ] : null,
            'dispositivo' => $maniobra->dispositivo ? [
                'id' => $maniobra->dispositivo->id,
                'codigo' => $maniobra->dispositivo->codigo,
                'nombre' => $maniobra->dispositivo->nombre,
            ] : null,
            'conflictos' => array_values($decision->conflictos ?? []),
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializarExplicacion(DecisionPersistida $decision): ?array
    {
        $explicacion = $decision->explicacion;
        if (! is_array($explicacion)) {
            return null;
        }

        $componentes = $explicacion['componentes'] ?? [];
        $recursos = $explicacion['recursos'] ?? [];

        return [
            'version' => (int) ($explicacion['version'] ?? 1),
            'reglas' => (string) ($explicacion['reglas'] ?? ''),
            'resumen' => (string) ($explicacion['resumen'] ?? $decision->motivo),
            'factor_decisivo' => [
                'codigo' => (string) ($explicacion['factor_decisivo']['codigo'] ?? ''),
                'etiqueta' => (string) ($explicacion['factor_decisivo']['etiqueta'] ?? ''),
            ],
            'formula' => (string) ($explicacion['formula'] ?? ''),
            'componentes' => [
                'realidad_fisica' => (bool) ($componentes['realidad_fisica'] ?? false),
                'prioridad' => [
                    'valor' => (string) ($componentes['prioridad']['valor'] ?? ''),
                    'peso' => (int) ($componentes['prioridad']['peso'] ?? 0),
                    'aporte' => (int) ($componentes['prioridad']['aporte'] ?? 0),
                ],
                'objetivo' => [
                    'tipo' => (string) ($componentes['objetivo']['tipo'] ?? ''),
                    'titulo' => (string) ($componentes['objetivo']['titulo'] ?? ''),
                    'peso' => (int) ($componentes['objetivo']['peso'] ?? 0),
                    'aporte' => (int) ($componentes['objetivo']['aporte'] ?? 0),
                ],
                'beneficio' => [
                    'estimado' => (int) ($componentes['beneficio']['estimado'] ?? 0),
                    'costo_movimientos' => (int) ($componentes['beneficio']['costo_movimientos'] ?? 0),
                    'riesgo_operacional' => (int) ($componentes['beneficio']['riesgo_operacional'] ?? 0),
                    'neto' => (int) ($componentes['beneficio']['neto'] ?? 0),
                    'aporte' => (int) ($componentes['beneficio']['aporte'] ?? 0),
                ],
                'puntaje' => (int) ($componentes['puntaje'] ?? $decision->puntaje),
            ],
            'capacidad' => collect($explicacion['capacidad'] ?? [])
                ->map(fn ($valor): int => (int) $valor)
                ->all(),
            'restricciones' => collect($explicacion['restricciones'] ?? [])
                ->filter(fn ($restriccion): bool => is_array($restriccion))
                ->map(fn (array $restriccion): array => [
                    'codigo' => (string) ($restriccion['codigo'] ?? ''),
                    'etiqueta' => (string) ($restriccion['etiqueta'] ?? ''),
                    'resultado' => (string) ($restriccion['resultado'] ?? ''),
                    'detalle' => (string) ($restriccion['detalle'] ?? ''),
                ])
                ->values()
                ->all(),
            'recursos' => [
                'requeridos' => $this->serializarRecursosExplicacion(
                    $recursos['requeridos'] ?? [],
                ),
                'conflictos' => collect($recursos['conflictos'] ?? [])
                    ->filter(fn ($conflicto): bool => is_array($conflicto))
                    ->map(fn (array $conflicto): array => [
                        'tipo' => (string) ($conflicto['tipo'] ?? ''),
                        'nombre' => (string) ($conflicto['nombre'] ?? ''),
                        'maniobra_titulo' => (string) ($conflicto['maniobra_titulo'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ],
            'snapshot' => $this->serializarSnapshotExplicacion(
                $explicacion['snapshot'] ?? [],
            ),
        ];
    }

    /**
     * @param  mixed  $recursos
     * @return array<int, array<string, string>>
     */
    private function serializarRecursosExplicacion($recursos): array
    {
        return collect(is_array($recursos) ? $recursos : [])
            ->filter(fn ($recurso): bool => is_array($recurso))
            ->map(fn (array $recurso): array => [
                'tipo' => (string) ($recurso['tipo'] ?? ''),
                'nombre' => (string) ($recurso['nombre'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $snapshot
     * @return array<string, mixed>
     */
    private function serializarSnapshotExplicacion($snapshot): array
    {
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $maniobra = is_array($snapshot['maniobra'] ?? null) ? $snapshot['maniobra'] : [];
        $objetivo = is_array($snapshot['objetivo'] ?? null) ? $snapshot['objetivo'] : [];

        return [
            'maniobra' => [
                'version' => (int) ($maniobra['version'] ?? 0),
                'titulo' => (string) ($maniobra['titulo'] ?? ''),
                'estado' => (string) ($maniobra['estado'] ?? ''),
                'prioridad' => (string) ($maniobra['prioridad'] ?? ''),
                'creada_at' => $maniobra['creada_at'] ?? null,
            ],
            'objetivo' => $objetivo !== [] ? [
                'version' => (int) ($objetivo['version'] ?? 0),
                'tipo' => (string) ($objetivo['tipo'] ?? ''),
                'estado' => (string) ($objetivo['estado'] ?? ''),
                'titulo' => (string) ($objetivo['titulo'] ?? ''),
            ] : null,
            'pasos' => collect($snapshot['pasos'] ?? [])
                ->filter(fn ($paso): bool => is_array($paso))
                ->map(fn (array $paso): array => [
                    'version' => (int) ($paso['version'] ?? 0),
                    'secuencia' => (int) ($paso['secuencia'] ?? 0),
                    'estado' => (string) ($paso['estado'] ?? ''),
                    'tipo_movimiento' => (string) ($paso['tipo_movimiento'] ?? ''),
                    'instruccion' => $paso['instruccion'] ?? null,
                    'folio' => $paso['folio']['numero_folio'] ?? null,
                    'origen' => $paso['origen']['etiqueta'] ?? null,
                    'destino' => $paso['destino']['etiqueta'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, TareaMovimiento>  $pasos
     */
    private function pasoActual(ManiobraOperacional $maniobra, Collection $pasos): ?TareaMovimiento
    {
        return $pasos->firstWhere('secuencia_maniobra', $maniobra->secuencia_actual)
            ?? $pasos->first(fn (TareaMovimiento $paso): bool => ! $paso->estado->esFinal());
    }

    /** @return array<string, mixed> */
    private function serializarPaso(TareaMovimiento $paso): array
    {
        return [
            'id' => $paso->id,
            'secuencia' => $paso->secuencia_maniobra,
            'estado' => $paso->estado->value,
            'tipo_movimiento' => $paso->tipo_movimiento->value,
            'instruccion' => $paso->instruccion,
            'folio' => $paso->folio ? [
                'id' => $paso->folio->id,
                'numero_folio' => $paso->folio->numero_folio,
            ] : null,
            'origen' => $this->serializarUbicacion($paso->camaraOrigen, $paso->posicionOrigen),
            'destino' => $this->serializarUbicacion($paso->camaraDestino, $paso->posicionDestino),
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializarUbicacion(?Camara $camara, ?Posicion $posicion): ?array
    {
        if (! $camara && ! $posicion) {
            return null;
        }

        return [
            // Se conservan estas claves para los consumidores actuales del puesto de mando.
            'id' => $camara?->id,
            'codigo' => $camara?->codigo,
            'nombre' => $camara?->nombre,
            'camara' => $camara ? [
                'id' => $camara->id,
                'codigo' => $camara->codigo,
                'nombre' => $camara->nombre,
            ] : null,
            'posicion' => $posicion ? [
                'id' => $posicion->id,
                'etiqueta' => $posicion->etiqueta,
                'banda' => $posicion->banda,
                'posicion' => $posicion->posicion,
                'nivel' => $posicion->nivel,
            ] : null,
        ];
    }
}
