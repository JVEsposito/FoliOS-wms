<?php

namespace App\Services\Planificador;

use App\Models\CicloArbitrajeManiobras;
use App\Models\DecisionArbitrajeManiobra;
use App\Models\Temporada;

final class ServicioReplayCiclosArbitraje
{
    private const VERSION_EXPLICACION = 1;

    private const LIMITE_VERIFICACIONES_PUBLICADAS = 100;

    public function __construct(
        private readonly ServicioEstadoArbitrajePlanificador $estadoArbitraje,
        private readonly MotorReplayArbitrajeV4 $motorV4,
        private readonly MotorReplayArbitrajeV5 $motorV5,
    ) {}

    /** @return array<string, mixed> */
    public function verificar(Temporada $temporada, string $referencia): array
    {
        $ciclo = $this->seleccionarCiclo($temporada, $referencia);
        if (! $ciclo) {
            return $this->sinReplay(
                $referencia === 'anterior' ? 'sin_ciclo_anterior' : 'sin_ciclo_actual',
                $referencia === 'anterior'
                    ? 'Todavía no existe un ciclo anterior para reproducir.'
                    : 'Todavía no existe un ciclo confirmado para reproducir.',
                $referencia,
            );
        }

        $ciclo->load(['decisiones' => fn ($consulta) => $consulta->with('maniobraOperacional:id,titulo')]);
        $contexto = is_array($ciclo->contexto) ? $ciclo->contexto : [];
        $reglas = (string) ($contexto['version_reglas'] ?? '');
        if (! in_array($reglas, [MotorReplayArbitrajeV4::VERSION_REGLAS, MotorReplayArbitrajeV5::VERSION_REGLAS], true)
            || ! array_key_exists('camaras_rollout', $contexto)) {
            return $this->insuficiente(
                $ciclo,
                $referencia,
                'El ciclo fue generado antes del contrato reproducible del planificador.',
            );
        }

        $normalizadas = $ciclo->decisiones
            ->map(fn (DecisionArbitrajeManiobra $decision): ?array => $this->normalizar($decision, $reglas));
        if ($normalizadas->contains(null)) {
            return $this->insuficiente(
                $ciclo,
                $referencia,
                'Una o más decisiones no conservan todos los insumos históricos requeridos.',
            );
        }

        /** @var array<int, array<string, mixed>> $candidatos */
        $candidatos = $normalizadas->values()->all();
        $motor = $reglas === MotorReplayArbitrajeV5::VERSION_REGLAS ? $this->motorV5 : $this->motorV4;
        $reproducidas = $motor->reproducir(
            $candidatos,
            $ciclo->capacidad_ejecucion,
            $ciclo->frontera_max,
            $contexto['camaras_rollout'],
        );
        $verificaciones = collect($candidatos)
            ->map(fn (array $candidato): array => $this->verificarDecision(
                $candidato,
                $reproducidas[$candidato['id']],
            ))
            ->sortBy(fn (array $verificacion): int => $verificacion['persistido']['orden'])
            ->values();
        $diferencias = $verificaciones->where('estado', 'difiere')->count();

        return [
            'disponible' => true,
            'estado' => $diferencias === 0 ? 'coincide' : 'difiere',
            'detalle' => $diferencias === 0
                ? 'El motor reprodujo todas las decisiones con los insumos históricos conservados.'
                : 'La reproducción encontró diferencias respecto del ciclo persistido.',
            'referencia' => $referencia,
            'ciclo' => $this->metadatosCiclo($ciclo, $reglas),
            'resumen' => [
                'decisiones' => $verificaciones->count(),
                'coinciden' => $verificaciones->where('estado', 'coincide')->count(),
                'difieren' => $diferencias,
                'insuficientes' => 0,
            ],
            'verificaciones' => $verificaciones
                ->sortBy(fn (array $verificacion): string => sprintf(
                    '%d-%08d',
                    $verificacion['estado'] === 'difiere' ? 0 : 1,
                    $verificacion['persistido']['orden'],
                ))
                ->take(self::LIMITE_VERIFICACIONES_PUBLICADAS)
                ->values()
                ->all(),
            'truncada' => $verificaciones->count() > self::LIMITE_VERIFICACIONES_PUBLICADAS,
            'limite' => self::LIMITE_VERIFICACIONES_PUBLICADAS,
        ];
    }

    private function seleccionarCiclo(Temporada $temporada, string $referencia): ?CicloArbitrajeManiobras
    {
        $actual = $this->estadoArbitraje->consultar($temporada)['ciclo'];
        if (! $actual || $referencia === 'actual') {
            return $actual;
        }

        return CicloArbitrajeManiobras::query()
            ->where('temporada_id', $temporada->id)
            ->whereKeyNot($actual->id)
            ->where('created_at', '<=', $actual->created_at)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function normalizar(DecisionArbitrajeManiobra $decision, string $reglas): ?array
    {
        $explicacion = is_array($decision->explicacion) ? $decision->explicacion : [];
        $snapshot = is_array($explicacion['snapshot'] ?? null) ? $explicacion['snapshot'] : [];
        $maniobra = is_array($snapshot['maniobra'] ?? null) ? $snapshot['maniobra'] : [];
        $plan = is_array($snapshot['objetivo'] ?? null) ? $snapshot['objetivo'] : [];
        $objetivos = is_array($snapshot['objetivos'] ?? null) ? $snapshot['objetivos'] : null;
        $pasos = is_array($snapshot['pasos'] ?? null) ? $snapshot['pasos'] : null;
        $componentes = is_array($explicacion['componentes'] ?? null) ? $explicacion['componentes'] : [];
        $beneficio = is_array($componentes['beneficio'] ?? null) ? $componentes['beneficio'] : [];
        $recursos = is_array($explicacion['recursos']['requeridos'] ?? null)
            ? $explicacion['recursos']['requeridos']
            : null;
        $factor = is_array($explicacion['factor_decisivo'] ?? null)
            ? $explicacion['factor_decisivo']
            : [];

        if (($explicacion['version'] ?? null) !== self::VERSION_EXPLICACION
            || ($explicacion['reglas'] ?? null) !== $reglas
            || ! is_string($maniobra['id'] ?? null)
            || $maniobra['id'] !== $decision->maniobra_operacional_id
            || ! is_string($maniobra['creada_at'] ?? null)
            || strtotime($maniobra['creada_at']) === false
            || ! is_string($maniobra['estado'] ?? null)
            || ! is_string($maniobra['prioridad'] ?? null)
            || ! is_string($plan['tipo'] ?? null)
            || ! is_string($plan['estado'] ?? null)
            || $objetivos === null
            || $pasos === null
            || ! is_bool($componentes['realidad_fisica'] ?? null)
            || ! $this->beneficioCompleto($beneficio)
            || $recursos === null
            || ! $this->recursosCompletos($recursos)
            || ! is_string($factor['codigo'] ?? null)) {
            return null;
        }

        $pesoPrioridad = $this->pesoPrioridad($maniobra['prioridad']);
        $pesoObjetivo = $this->pesoObjetivo($objetivos, $plan['tipo']);
        if ($pesoPrioridad === null || $pesoObjetivo === null) {
            return null;
        }

        return [
            'id' => $maniobra['id'],
            'titulo' => (string) ($maniobra['titulo'] ?? $decision->maniobraOperacional?->titulo ?? 'Maniobra sin título'),
            'estado' => $maniobra['estado'],
            'creada_timestamp' => strtotime($maniobra['creada_at']),
            'plan_tipo' => $plan['tipo'],
            'plan_estado' => $plan['estado'],
            'objetivo' => (string) ($plan['titulo'] ?? ''),
            'folio' => $this->primerFolio($pasos),
            'ruta' => $this->primeraRuta($pasos),
            'realidad_fisica' => $componentes['realidad_fisica'],
            'peso_prioridad' => $pesoPrioridad,
            'peso_objetivo' => $pesoObjetivo,
            'beneficio_estimado' => (int) $beneficio['estimado'],
            'costo_movimientos' => (int) $beneficio['costo_movimientos'],
            'riesgo_operacional' => (int) $beneficio['riesgo_operacional'],
            'recursos' => collect($recursos)
                ->map(fn ($recurso): ?string => is_array($recurso) && is_string($recurso['clave'] ?? null)
                    ? $recurso['clave']
                    : null)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'camaras' => $this->camaras($pasos, $recursos),
            'persistido' => [
                'orden' => $decision->orden,
                'decision' => $decision->decision->value,
                'peso_prioridad' => (int) ($componentes['prioridad']['peso'] ?? PHP_INT_MIN),
                'peso_objetivo' => (int) ($componentes['objetivo']['peso'] ?? PHP_INT_MIN),
                'beneficio_neto' => $decision->beneficio_neto,
                'puntaje' => $decision->puntaje,
                'factor_decisivo' => $factor['codigo'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $beneficio */
    private function beneficioCompleto(array $beneficio): bool
    {
        foreach (['estimado', 'costo_movimientos', 'riesgo_operacional'] as $campo) {
            if (! is_int($beneficio[$campo] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<int, mixed>  $recursos */
    private function recursosCompletos(array $recursos): bool
    {
        foreach ($recursos as $recurso) {
            if (! is_array($recurso) || ! is_string($recurso['clave'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function pesoPrioridad(string $prioridad): ?int
    {
        return match ($prioridad) {
            'normal' => 10,
            'alta' => 20,
            'urgente' => 30,
            'critica' => 40,
            default => null,
        };
    }

    /** @param  array<int, mixed>  $objetivos */
    private function pesoObjetivo(array $objetivos, string $tipoPlan): ?int
    {
        $tipos = collect($objetivos)
            ->map(fn ($objetivo): ?string => is_array($objetivo) && is_string($objetivo['tipo'] ?? null)
                ? $objetivo['tipo']
                : null)
            ->filter()
            ->values();
        if ($tipos->isEmpty()) {
            $tipos->push($tipoPlan);
        }

        return $tipos->map(fn (string $tipo): int => match ($tipo) {
            'evacuacion_emergencia' => 40,
            'despacho_directo' => 30,
            'segregacion_retenido' => 20,
            default => 10,
        })->max();
    }

    /** @param  array<int, mixed>  $pasos */
    private function primerFolio(array $pasos): string
    {
        $paso = collect($pasos)->sortBy('secuencia')->first();

        return is_array($paso) ? (string) ($paso['folio']['numero_folio'] ?? '') : '';
    }

    /** @param  array<int, mixed>  $pasos */
    private function primeraRuta(array $pasos): string
    {
        $paso = collect($pasos)->sortBy('secuencia')->first();
        if (! is_array($paso)) {
            return '';
        }
        $origen = trim((string) ($paso['origen']['etiqueta'] ?? ''));
        $destino = trim((string) ($paso['destino']['etiqueta'] ?? ''));

        return match (true) {
            $origen !== '' && $destino !== '' => $origen.' → '.$destino,
            $destino !== '' => 'Inicio → '.$destino,
            $origen !== '' => $origen.' → Sin destino',
            default => '',
        };
    }

    /**
     * @param  array<int, mixed>  $pasos
     * @param  array<int, mixed>  $recursos
     * @return array<int, string>
     */
    private function camaras(array $pasos, array $recursos): array
    {
        return collect($pasos)
            ->filter(fn ($paso): bool => is_array($paso))
            ->flatMap(fn (array $paso): array => [
                $paso['origen']['camara_id'] ?? null,
                $paso['destino']['camara_id'] ?? null,
            ])
            ->merge(collect($recursos)->map(function ($recurso): ?string {
                $clave = is_array($recurso) ? ($recurso['clave'] ?? null) : null;
                if (! is_string($clave) || ! str_starts_with($clave, 'banda:')) {
                    return null;
                }

                return explode(':', $clave, 3)[1] ?? null;
            }))
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidato
     * @param  array<string, int|string>  $reproducido
     * @return array<string, mixed>
     */
    private function verificarDecision(array $candidato, array $reproducido): array
    {
        $campos = [
            'orden',
            'decision',
            'peso_prioridad',
            'peso_objetivo',
            'beneficio_neto',
            'puntaje',
            'factor_decisivo',
        ];
        $diferencias = collect($campos)
            ->filter(fn (string $campo): bool => $candidato['persistido'][$campo] !== $reproducido[$campo])
            ->map(fn (string $campo): array => [
                'campo' => $campo,
                'persistido' => $candidato['persistido'][$campo],
                'reproducido' => $reproducido[$campo],
            ])
            ->values()
            ->all();

        return [
            'estado' => $diferencias === [] ? 'coincide' : 'difiere',
            'maniobra' => [
                'titulo' => $candidato['titulo'],
                'objetivo' => $candidato['objetivo'],
                'folio' => $candidato['folio'],
                'ruta' => $candidato['ruta'],
            ],
            'persistido' => $candidato['persistido'],
            'reproducido' => $reproducido,
            'diferencias' => $diferencias,
        ];
    }

    /** @return array<string, mixed> */
    private function insuficiente(
        CicloArbitrajeManiobras $ciclo,
        string $referencia,
        string $detalle,
    ): array {
        $decisiones = $ciclo->decisiones()->count();

        return [
            'disponible' => false,
            'estado' => 'informacion_insuficiente',
            'detalle' => $detalle,
            'referencia' => $referencia,
            'ciclo' => $this->metadatosCiclo(
                $ciclo,
                (string) (($ciclo->contexto ?? [])['version_reglas'] ?? ''),
            ),
            'resumen' => [
                'decisiones' => $decisiones,
                'coinciden' => 0,
                'difieren' => 0,
                'insuficientes' => $decisiones,
            ],
            'verificaciones' => [],
            'truncada' => false,
            'limite' => self::LIMITE_VERIFICACIONES_PUBLICADAS,
        ];
    }

    /** @return array<string, mixed> */
    private function sinReplay(string $estado, string $detalle, string $referencia): array
    {
        return [
            'disponible' => false,
            'estado' => $estado,
            'detalle' => $detalle,
            'referencia' => $referencia,
            'ciclo' => null,
            'resumen' => [
                'decisiones' => 0,
                'coinciden' => 0,
                'difieren' => 0,
                'insuficientes' => 0,
            ],
            'verificaciones' => [],
            'truncada' => false,
            'limite' => self::LIMITE_VERIFICACIONES_PUBLICADAS,
        ];
    }

    /** @return array<string, mixed> */
    private function metadatosCiclo(CicloArbitrajeManiobras $ciclo, string $reglas): array
    {
        return [
            'generado_at' => $ciclo->created_at?->toIso8601String(),
            'reglas' => $reglas,
            'capacidad_ejecucion' => $ciclo->capacidad_ejecucion,
            'frontera_max' => $ciclo->frontera_max,
        ];
    }
}
