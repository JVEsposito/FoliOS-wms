<?php

namespace App\Services\Planificador;

use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\CicloArbitrajeManiobras;
use App\Models\DecisionArbitrajeManiobra as DecisionPersistida;
use App\Models\ManiobraOperacional;
use App\Models\Temporada;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioArbitrajeManiobras
{
    private const VERSION_REGLAS = 'arbitraje_global_v5_pausa_fisica';

    public function __construct(
        private readonly ServicioDesplieguePlanificador $despliegue,
        private readonly ServicioExplicabilidadArbitraje $explicabilidad,
    ) {}

    /**
     * Calcula y conserva la frontera global vigente. Un mismo estado físico y
     * operacional reutiliza el ciclo anterior en vez de generar ruido de auditoría.
     */
    public function arbitrar(Temporada $temporada): CicloArbitrajeManiobras
    {
        $snapshot = null;

        try {
            return DB::transaction(function () use ($temporada, &$snapshot): CicloArbitrajeManiobras {
                $maniobras = $this->maniobrasVigentes($temporada);
                $camarasRollout = $this->camarasRolloutArbitraje();
                $snapshot = $this->snapshot($temporada, $maniobras, $camarasRollout);
                $existente = CicloArbitrajeManiobras::query()
                    ->where('snapshot_version', $snapshot)
                    ->first();
                if ($existente) {
                    return $existente->load('decisiones');
                }

                $capacidad = max(1, (int) config('planificador.maniobras_simultaneas_max', 3));
                $fronteraMax = max(1, (int) config('planificador.frontier_max', 4));
                $decisiones = $this->resolver(
                    $maniobras,
                    $capacidad,
                    $fronteraMax,
                    $camarasRollout,
                );
                $ciclo = CicloArbitrajeManiobras::create([
                    'temporada_id' => $temporada->id,
                    'snapshot_version' => $snapshot,
                    'capacidad_ejecucion' => $capacidad,
                    'frontera_max' => $fronteraMax,
                    'contexto' => [
                        'version_reglas' => self::VERSION_REGLAS,
                        'criterio' => 'prioridad_tipo_beneficio_neto_antiguedad',
                        'formula_beneficio_neto' => 'beneficio_estimado-costo_movimientos-riesgo_operacional',
                        'camaras_rollout' => $camarasRollout,
                        'tipos_fuera_planificador' => [
                            TipoPlanOperacional::RecepcionRepaletizaje->value,
                        ],
                    ],
                ]);

                foreach ($decisiones as $decision) {
                    $ciclo->decisiones()->create($decision);
                }

                return $ciclo->load('decisiones');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $excepcion) {
            $cicloConcurrente = $snapshot
                ? CicloArbitrajeManiobras::query()
                    ->where('snapshot_version', $snapshot)
                    ->first()
                : null;
            if ($cicloConcurrente) {
                return $cicloConcurrente->load('decisiones');
            }

            throw $excepcion;
        }
    }

    public function validarAsumible(ManiobraOperacional $maniobra): ?DecisionPersistida
    {
        if (config('planificador.mode') !== 'guided'
            || $maniobra->estado !== EstadoManiobraOperacional::Pendiente) {
            return null;
        }

        $maniobra->loadMissing('planOperacional.temporada');
        if ($this->fueraPlanificador($maniobra)) {
            return null;
        }

        $temporada = $maniobra->planOperacional?->temporada;
        if (! $temporada?->activa) {
            throw new ConflictoOperacion('La maniobra no pertenece a la temporada operacional activa.');
        }

        $decision = $this->arbitrar($temporada)->decisiones
            ->firstWhere('maniobra_operacional_id', $maniobra->id);
        if (! $decision || ! $decision->decision->asumible()) {
            if ($decision?->decision === DecisionArbitrajeManiobra::Alternativa) {
                throw new ConflictoOperacion(
                    'Ya existen tres maniobras asumidas o seleccionadas; la cuarta debe permanecer como alternativa.',
                );
            }
            if ($decision?->decision === DecisionArbitrajeManiobra::ExcluidaConflicto
                && collect($decision->conflictos)->contains(
                    fn (array $conflicto): bool => str_starts_with(
                        $conflicto['recurso'] ?? '',
                        'banda:',
                    ),
                )) {
                throw new ConflictoOperacion(
                    'La banda requerida está comprometida por otra maniobra.',
                );
            }

            throw new ConflictoOperacion(
                $decision?->motivo ?? 'La maniobra quedó fuera de la frontera operacional vigente.',
            );
        }

        return $decision;
    }

    public function validarMaterializable(ManiobraOperacional $maniobra): ?DecisionPersistida
    {
        if (config('planificador.mode') !== 'guided') {
            return null;
        }

        if (in_array($maniobra->estado, [
            EstadoManiobraOperacional::PausadaDiscrepancia,
            EstadoManiobraOperacional::PausadaSupervision,
        ], true)) {
            throw new ConflictoOperacion(
                'La maniobra está pausada y no admite cambios físicos.',
            );
        }

        $maniobra->loadMissing('planOperacional.temporada');
        if ($this->fueraPlanificador($maniobra)) {
            return null;
        }

        $temporada = $maniobra->planOperacional?->temporada;
        if (! $temporada?->activa) {
            throw new ConflictoOperacion('La maniobra no pertenece a la temporada operacional activa.');
        }

        $decision = $this->arbitrar($temporada)->decisiones
            ->firstWhere('maniobra_operacional_id', $maniobra->id);
        if (! $decision || ! $decision->decision->materializable()) {
            throw new ConflictoOperacion(
                'La maniobra no pertenece a la frontera física autoritativa vigente.',
            );
        }

        return $decision;
    }

    /** @return Collection<int, string> */
    public function idsPublicables(CicloArbitrajeManiobras $ciclo): Collection
    {
        $ciclo->loadMissing('decisiones');

        return $ciclo->decisiones
            ->filter(fn (DecisionPersistida $decision): bool => $decision->decision->publicable())
            ->pluck('maniobra_operacional_id')
            ->values();
    }

    /**
     * @return Collection<int, ManiobraOperacional>
     */
    private function maniobrasVigentes(Temporada $temporada): Collection
    {
        return ManiobraOperacional::query()
            ->whereHas(
                'planOperacional',
                fn ($consulta) => $consulta
                    ->where('temporada_id', $temporada->id)
                    ->whereIn('estado', [
                        EstadoPlanOperacional::Programado->value,
                        EstadoPlanOperacional::EnEjecucion->value,
                        EstadoPlanOperacional::Pausado->value,
                    ]),
            )
            ->whereIn('estado', [
                EstadoManiobraOperacional::Pendiente->value,
                EstadoManiobraOperacional::EnEjecucion->value,
                EstadoManiobraOperacional::PausadaDiscrepancia->value,
                EstadoManiobraOperacional::PausadaSupervision->value,
            ])
            ->with([
                'planOperacional.temporada',
                'objetivos:id,tipo,estado,prioridad,titulo',
                'pasos' => fn ($consulta) => $consulta
                    ->whereIn('estado', [
                        EstadoTareaMovimiento::Bloqueada->value,
                        EstadoTareaMovimiento::Pendiente->value,
                        EstadoTareaMovimiento::Asumida->value,
                        EstadoTareaMovimiento::EnProceso->value,
                    ])
                    ->with([
                        'folio:id,numero_folio',
                        'camaraOrigen:id,codigo,nombre',
                        'posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
                        'camaraDestino:id,codigo,nombre',
                        'posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
                    ]),
                'reservasBandas' => fn ($consulta) => $consulta
                    ->whereNull('liberada_at')
                    ->with('camara:id,codigo,nombre'),
                'custodiasTemporales' => fn ($consulta) => $consulta->where(
                    'estado',
                    EstadoCustodiaTemporal::Activa->value,
                ),
            ])
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, ManiobraOperacional>  $maniobras
     * @return array<int, array<string, mixed>>
     */
    private function resolver(
        Collection $maniobras,
        int $capacidad,
        int $fronteraMax,
        ?array $camarasRollout,
    ): array {
        $ordenadas = $maniobras
            ->sort(function (ManiobraOperacional $izquierda, ManiobraOperacional $derecha): int {
                $vectorIzquierda = $this->vectorOrden($izquierda);
                $vectorDerecha = $this->vectorOrden($derecha);
                foreach (array_keys($vectorIzquierda) as $indice) {
                    $comparacion = $vectorDerecha[$indice] <=> $vectorIzquierda[$indice];
                    if ($comparacion !== 0) {
                        return $comparacion;
                    }
                }

                $comparacionFecha = $izquierda->created_at->getTimestamp()
                    <=> $derecha->created_at->getTimestamp();

                return $comparacionFecha !== 0
                    ? $comparacionFecha
                    : strcmp($izquierda->id, $derecha->id);
            })
            ->values();
        $ocupantes = $ordenadas->filter(function (ManiobraOperacional $maniobra) use (
            $camarasRollout,
        ): bool {
            if ($this->fueraPlanificador($maniobra)) {
                return false;
            }

            return $this->realidadFisicaIniciada($maniobra)
                || ($maniobra->estado === EstadoManiobraOperacional::EnEjecucion
                    && ! $this->fueraRollout($maniobra, $camarasRollout));
        });
        $cupos = max(0, $capacidad - $ocupantes->count());
        $seleccionadas = 0;
        $publicadas = 0;
        $alternativaAsignada = false;
        $recursosTomados = [];
        $decisiones = [];
        $orden = 0;

        foreach ($ordenadas as $maniobra) {
            $orden++;
            $componentes = $this->componentesPuntaje($maniobra);
            $beneficioNeto = $componentes['beneficio']['neto'];
            $puntaje = $componentes['puntaje'];
            $conflictos = $this->conflictos($maniobra, $recursosTomados);
            $fueraRollout = $this->fueraRollout($maniobra, $camarasRollout);
            $realidadFisicaIniciada = $this->realidadFisicaIniciada($maniobra);
            $capacidadDecision = [
                'capacidad_ejecucion' => $capacidad,
                'frontera_max' => $fronteraMax,
                'ocupantes_fisicos' => $ocupantes->count(),
                'cupos_disponibles' => $cupos,
                'seleccionadas_antes' => $seleccionadas,
                'publicadas_antes' => $publicadas,
            ];

            if ($this->fueraPlanificador($maniobra)) {
                $decision = DecisionArbitrajeManiobra::FueraPlanificador;
                $factorDecisivo = 'fuera_planificador';
                $motivo = 'La recepción desde REPA conserva su retiro independiente del planificador global.';
                if ($maniobra->estado !== EstadoManiobraOperacional::Pendiente) {
                    $this->ocuparRecursos($maniobra, $recursosTomados);
                }
            } elseif ($maniobra->estado === EstadoManiobraOperacional::PausadaSupervision) {
                $decision = DecisionArbitrajeManiobra::FueraFrontera;
                $factorDecisivo = 'pausa_supervision';
                $motivo = 'La maniobra permanece pausada por supervisión antes de iniciar.';
            } elseif ($maniobra->estado === EstadoManiobraOperacional::PausadaDiscrepancia
                && ! $realidadFisicaIniciada) {
                $decision = DecisionArbitrajeManiobra::FueraFrontera;
                $factorDecisivo = 'pausa_discrepancia_sin_retiro';
                $motivo = 'La discrepancia se reportó antes del retiro: la maniobra espera supervisión y libera el cupo.';
            } elseif ($realidadFisicaIniciada
                || ($maniobra->estado === EstadoManiobraOperacional::EnEjecucion
                    && ! $fueraRollout)) {
                $decision = DecisionArbitrajeManiobra::EnEjecucion;
                $factorDecisivo = 'realidad_fisica_iniciada';
                $motivo = 'La realidad física iniciada prevalece y conserva sus recursos.';
                $this->ocuparRecursos($maniobra, $recursosTomados);
            } elseif ($fueraRollout) {
                $decision = DecisionArbitrajeManiobra::FueraRollout;
                $factorDecisivo = 'fuera_rollout';
                $motivo = 'La maniobra permanece en shadow porque involucra una cámara fuera del rollout dirigido.';
                if ($maniobra->estado !== EstadoManiobraOperacional::Pendiente) {
                    $this->ocuparRecursos($maniobra, $recursosTomados);
                }
            } elseif ($maniobra->planOperacional?->estado === EstadoPlanOperacional::Pausado) {
                $decision = DecisionArbitrajeManiobra::FueraFrontera;
                $factorDecisivo = 'objetivo_pausado';
                $motivo = 'El objetivo permanece pausado por supervisión.';
            } elseif ($conflictos !== []) {
                $decision = DecisionArbitrajeManiobra::ExcluidaConflicto;
                $factorDecisivo = 'conflicto_recursos';
                $motivo = 'La maniobra comparte recursos con otra labor de mayor precedencia.';
            } elseif ($seleccionadas < min($cupos, $fronteraMax)) {
                $decision = DecisionArbitrajeManiobra::Seleccionada;
                $factorDecisivo = 'cupo_disponible';
                $motivo = 'Seleccionada por prioridad, objetivo dominante y beneficio neto.';
                $seleccionadas++;
                $publicadas++;
                $this->ocuparRecursos($maniobra, $recursosTomados);
            } elseif (! $alternativaAsignada && $publicadas < $fronteraMax) {
                $decision = DecisionArbitrajeManiobra::Alternativa;
                $factorDecisivo = 'alternativa_sin_reserva';
                $motivo = 'Alternativa visible sin reservas físicas hasta liberar capacidad.';
                $alternativaAsignada = true;
                $publicadas++;
            } else {
                $decision = DecisionArbitrajeManiobra::FueraFrontera;
                $factorDecisivo = 'frontera_completa';
                $motivo = 'La maniobra permanece pendiente fuera de la frontera corta vigente.';
            }

            $capacidadDecision['seleccionadas_despues'] = $seleccionadas;
            $capacidadDecision['publicadas_despues'] = $publicadas;

            $decisiones[] = [
                'maniobra_operacional_id' => $maniobra->id,
                'orden' => $orden,
                'decision' => $decision,
                'puntaje' => $puntaje,
                'beneficio_neto' => $beneficioNeto,
                'motivo' => $motivo,
                'conflictos' => $conflictos !== [] ? $conflictos : null,
                'explicacion' => $this->explicabilidad->construir(
                    reglas: self::VERSION_REGLAS,
                    maniobra: $maniobra,
                    decision: $decision,
                    factorDecisivo: $factorDecisivo,
                    motivo: $motivo,
                    componentes: $componentes,
                    capacidad: $capacidadDecision,
                    recursos: $this->recursos($maniobra),
                    conflictos: $conflictos,
                    maniobras: $ordenadas,
                ),
            ];
        }

        return $decisiones;
    }

    /** @return array<int, int> */
    private function vectorOrden(ManiobraOperacional $maniobra): array
    {
        return [
            ($maniobra->estado === EstadoManiobraOperacional::EnEjecucion
                || $this->realidadFisicaIniciada($maniobra)) ? 1 : 0,
            $maniobra->prioridad->peso(),
            $this->pesoObjetivo($maniobra),
            $this->beneficioNeto($maniobra),
        ];
    }

    private function pesoObjetivo(ManiobraOperacional $maniobra): int
    {
        return $maniobra->objetivos
            ->map(fn ($objetivo): int => $this->pesoTipoObjetivo($objetivo->tipo))
            ->max() ?? 10;
    }

    private function beneficioNeto(ManiobraOperacional $maniobra): int
    {
        return $maniobra->beneficio_estimado
            - $maniobra->costo_movimientos
            - $maniobra->riesgo_operacional;
    }

    private function puntaje(ManiobraOperacional $maniobra, int $beneficioNeto): int
    {
        return ($maniobra->prioridad->peso() * 1_000_000_000)
            + ($this->pesoObjetivo($maniobra) * 10_000_000)
            + $beneficioNeto;
    }

    /** @return array<string, mixed> */
    private function componentesPuntaje(ManiobraOperacional $maniobra): array
    {
        $beneficioNeto = $this->beneficioNeto($maniobra);
        $pesoPrioridad = $maniobra->prioridad->peso();
        $pesoObjetivo = $this->pesoObjetivo($maniobra);
        $objetivoDominante = $maniobra->objetivos
            ->sortByDesc(fn ($objetivo): int => $this->pesoTipoObjetivo($objetivo->tipo))
            ->first() ?? $maniobra->planOperacional;

        return [
            'realidad_fisica' => $this->realidadFisicaIniciada($maniobra),
            'prioridad' => [
                'valor' => $maniobra->prioridad->value,
                'peso' => $pesoPrioridad,
                'aporte' => $pesoPrioridad * 1_000_000_000,
            ],
            'objetivo' => [
                'tipo' => $objetivoDominante?->tipo?->value,
                'titulo' => $objetivoDominante?->titulo,
                'peso' => $pesoObjetivo,
                'aporte' => $pesoObjetivo * 10_000_000,
            ],
            'beneficio' => [
                'estimado' => $maniobra->beneficio_estimado,
                'costo_movimientos' => $maniobra->costo_movimientos,
                'riesgo_operacional' => $maniobra->riesgo_operacional,
                'neto' => $beneficioNeto,
                'aporte' => $beneficioNeto,
            ],
            'puntaje' => $this->puntaje($maniobra, $beneficioNeto),
        ];
    }

    private function pesoTipoObjetivo(TipoPlanOperacional $tipo): int
    {
        return match ($tipo) {
            TipoPlanOperacional::EvacuacionEmergencia => 40,
            TipoPlanOperacional::DespachoDirecto => 30,
            TipoPlanOperacional::SegregacionRetenido => 20,
            default => 10,
        };
    }

    /** @param  array<string, string>  $recursosTomados */
    private function conflictos(ManiobraOperacional $maniobra, array $recursosTomados): array
    {
        return collect($this->recursos($maniobra))
            ->filter(fn (string $recurso): bool => isset($recursosTomados[$recurso]))
            ->map(fn (string $recurso): array => [
                'recurso' => $recurso,
                'maniobra_id' => $recursosTomados[$recurso],
            ])
            ->values()
            ->all();
    }

    /** @param  array<string, string>  $recursosTomados */
    private function ocuparRecursos(ManiobraOperacional $maniobra, array &$recursosTomados): void
    {
        foreach ($this->recursos($maniobra) as $recurso) {
            $recursosTomados[$recurso] = $maniobra->id;
        }
    }

    /** @return array<int, string> */
    private function recursos(ManiobraOperacional $maniobra): array
    {
        $recursos = $maniobra->pasos
            ->flatMap(fn ($paso): array => array_values(array_filter([
                $paso->folio_id ? "folio:{$paso->folio_id}" : null,
                $paso->posicion_origen_id ? "posicion:{$paso->posicion_origen_id}" : null,
                $paso->posicion_destino_id ? "posicion:{$paso->posicion_destino_id}" : null,
            ])))
            ->merge($maniobra->reservasBandas->map(
                fn ($reserva): string => implode(':', [
                    'banda',
                    $reserva->camara_id,
                    $reserva->banda,
                    $reserva->nivel,
                ]),
            ));

        return $recursos->unique()->sort()->values()->all();
    }

    private function fueraPlanificador(ManiobraOperacional $maniobra): bool
    {
        return $maniobra->planOperacional?->tipo === TipoPlanOperacional::RecepcionRepaletizaje;
    }

    /** @return array<int, string>|null */
    private function camarasRolloutArbitraje(): ?array
    {
        return match (config('planificador.mode')) {
            'guided' => $this->despliegue->idsCamarasDirigidas(),
            'shadow' => $this->despliegue->idsCamarasRollout(),
            default => [],
        };
    }

    /** @param  array<int, string>|null  $camarasRollout */
    private function fueraRollout(
        ManiobraOperacional $maniobra,
        ?array $camarasRollout,
    ): bool {
        if ($camarasRollout === null) {
            return false;
        }

        $camaras = $this->camarasInvolucradas($maniobra);

        return $camarasRollout === []
            || $camaras->contains(fn (string $id): bool => ! in_array($id, $camarasRollout, true));
    }

    /** @return Collection<int, string> */
    private function camarasInvolucradas(ManiobraOperacional $maniobra): Collection
    {
        return $maniobra->pasos
            ->flatMap(fn ($paso): array => array_values(array_filter([
                $paso->camara_origen_id,
                $paso->camara_destino_id,
            ])))
            ->merge($maniobra->reservasBandas->pluck('camara_id'))
            ->merge($maniobra->custodiasTemporales->pluck('camara_origen_id'))
            ->filter(fn (mixed $id): bool => is_string($id))
            ->unique()
            ->values();
    }

    private function realidadFisicaIniciada(ManiobraOperacional $maniobra): bool
    {
        // custodiasTemporales se carga filtrada por estado activa en maniobrasVigentes().
        return $maniobra->pasos->contains(
            fn ($paso): bool => $paso->estado === EstadoTareaMovimiento::EnProceso,
        )
            || $maniobra->custodiasTemporales->isNotEmpty();
    }

    /**
     * @param  Collection<int, ManiobraOperacional>  $maniobras
     * @param  array<int, string>|null  $camarasRollout
     */
    private function snapshot(
        Temporada $temporada,
        Collection $maniobras,
        ?array $camarasRollout,
    ): string {
        $estado = $maniobras
            ->sortBy('id')
            ->map(fn (ManiobraOperacional $maniobra): array => [
                'id' => $maniobra->id,
                'version' => $maniobra->version,
                'estado' => $maniobra->estado->value,
                'prioridad' => $maniobra->prioridad->value,
                'beneficio' => $maniobra->beneficio_estimado,
                'costo' => $maniobra->costo_movimientos,
                'riesgo' => $maniobra->riesgo_operacional,
                'plan' => [
                    'id' => $maniobra->planOperacional?->id,
                    'tipo' => $maniobra->planOperacional?->tipo->value,
                    'estado' => $maniobra->planOperacional?->estado->value,
                    'version' => $maniobra->planOperacional?->version,
                ],
                'objetivos' => $maniobra->objetivos
                    ->sortBy('id')
                    ->map(fn ($objetivo): array => [
                        $objetivo->id,
                        $objetivo->tipo->value,
                        $objetivo->estado->value,
                        $objetivo->prioridad->value,
                        (int) $objetivo->pivot->beneficio_estimado,
                    ])->values()->all(),
                'pasos' => $maniobra->pasos
                    ->sortBy('id')
                    ->map(fn ($paso): array => [
                        $paso->id,
                        $paso->estado->value,
                        $paso->version,
                        $paso->camara_origen_id,
                        $paso->camara_destino_id,
                    ])->values()->all(),
                'custodias_activas' => $maniobra->custodiasTemporales
                    ->sortBy('id')
                    ->map(fn ($custodia): array => [
                        $custodia->id,
                        $custodia->folio_id,
                        $custodia->camara_origen_id,
                    ])->values()->all(),
                'recursos' => $this->recursos($maniobra),
            ])->values()->all();

        return hash('sha256', json_encode([
            'reglas' => self::VERSION_REGLAS,
            'temporada' => $temporada->id,
            'capacidad' => config('planificador.maniobras_simultaneas_max', 3),
            'frontera' => config('planificador.frontier_max', 4),
            'camaras_rollout' => $camarasRollout,
            'maniobras' => $estado,
        ], JSON_THROW_ON_ERROR));
    }
}
