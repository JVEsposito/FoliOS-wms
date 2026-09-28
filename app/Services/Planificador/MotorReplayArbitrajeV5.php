<?php

namespace App\Services\Planificador;

final class MotorReplayArbitrajeV5
{
    public const VERSION_REGLAS = 'arbitraje_global_v5_pausa_fisica';

    /**
     * @param  array<int, array<string, mixed>>  $candidatos
     * @param  array<int, string>|null  $camarasRollout
     * @return array<string, array<string, int|string>>
     */
    public function reproducir(
        array $candidatos,
        int $capacidad,
        int $fronteraMax,
        ?array $camarasRollout,
    ): array {
        usort($candidatos, fn (array $izquierda, array $derecha): int => $this->comparar(
            $izquierda,
            $derecha,
        ));

        $ocupantes = count(array_filter(
            $candidatos,
            fn (array $candidato): bool => ! $this->fueraPlanificador($candidato)
                && ($candidato['realidad_fisica']
                    || ($candidato['estado'] === 'en_ejecucion'
                        && ! $this->fueraRollout($candidato, $camarasRollout))),
        ));
        $cupos = max(0, $capacidad - $ocupantes);
        $seleccionadas = 0;
        $publicadas = 0;
        $alternativaAsignada = false;
        $recursosTomados = [];
        $resultado = [];

        foreach ($candidatos as $indice => $candidato) {
            $fueraRollout = $this->fueraRollout($candidato, $camarasRollout);
            $conflicto = $this->alguno(
                $candidato['recursos'],
                fn (string $recurso): bool => isset($recursosTomados[$recurso]),
            );

            if ($this->fueraPlanificador($candidato)) {
                [$decision, $factor] = ['fuera_planificador', 'fuera_planificador'];
                if ($candidato['estado'] !== 'pendiente') {
                    $this->ocuparRecursos($candidato, $recursosTomados);
                }
            } elseif ($candidato['estado'] === 'pausada_supervision') {
                [$decision, $factor] = ['fuera_frontera', 'pausa_supervision'];
            } elseif ($candidato['estado'] === 'pausada_discrepancia'
                && ! $candidato['realidad_fisica']) {
                [$decision, $factor] = ['fuera_frontera', 'pausa_discrepancia_sin_retiro'];
            } elseif ($candidato['realidad_fisica']
                || ($candidato['estado'] === 'en_ejecucion' && ! $fueraRollout)) {
                [$decision, $factor] = ['en_ejecucion', 'realidad_fisica_iniciada'];
                $this->ocuparRecursos($candidato, $recursosTomados);
            } elseif ($fueraRollout) {
                [$decision, $factor] = ['fuera_rollout', 'fuera_rollout'];
                if ($candidato['estado'] !== 'pendiente') {
                    $this->ocuparRecursos($candidato, $recursosTomados);
                }
            } elseif ($candidato['plan_estado'] === 'pausado') {
                [$decision, $factor] = ['fuera_frontera', 'objetivo_pausado'];
            } elseif ($conflicto) {
                [$decision, $factor] = ['excluida_conflicto', 'conflicto_recursos'];
            } elseif ($seleccionadas < min($cupos, $fronteraMax)) {
                [$decision, $factor] = ['seleccionada', 'cupo_disponible'];
                $seleccionadas++;
                $publicadas++;
                $this->ocuparRecursos($candidato, $recursosTomados);
            } elseif (! $alternativaAsignada && $publicadas < $fronteraMax) {
                [$decision, $factor] = ['alternativa', 'alternativa_sin_reserva'];
                $alternativaAsignada = true;
                $publicadas++;
            } else {
                [$decision, $factor] = ['fuera_frontera', 'frontera_completa'];
            }

            $beneficioNeto = $candidato['beneficio_estimado']
                - $candidato['costo_movimientos']
                - $candidato['riesgo_operacional'];
            $puntaje = ($candidato['peso_prioridad'] * 1_000_000_000)
                + ($candidato['peso_objetivo'] * 10_000_000)
                + $beneficioNeto;

            $resultado[$candidato['id']] = [
                'orden' => $indice + 1,
                'decision' => $decision,
                'peso_prioridad' => $candidato['peso_prioridad'],
                'peso_objetivo' => $candidato['peso_objetivo'],
                'beneficio_neto' => $beneficioNeto,
                'puntaje' => $puntaje,
                'factor_decisivo' => $factor,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $izquierda
     * @param  array<string, mixed>  $derecha
     */
    private function comparar(array $izquierda, array $derecha): int
    {
        $vectorIzquierda = [
            ($izquierda['estado'] === 'en_ejecucion' || $izquierda['realidad_fisica']) ? 1 : 0,
            $izquierda['peso_prioridad'],
            $izquierda['peso_objetivo'],
            $izquierda['beneficio_estimado'] - $izquierda['costo_movimientos'] - $izquierda['riesgo_operacional'],
        ];
        $vectorDerecha = [
            ($derecha['estado'] === 'en_ejecucion' || $derecha['realidad_fisica']) ? 1 : 0,
            $derecha['peso_prioridad'],
            $derecha['peso_objetivo'],
            $derecha['beneficio_estimado'] - $derecha['costo_movimientos'] - $derecha['riesgo_operacional'],
        ];

        foreach (array_keys($vectorIzquierda) as $indice) {
            $comparacion = $vectorDerecha[$indice] <=> $vectorIzquierda[$indice];
            if ($comparacion !== 0) {
                return $comparacion;
            }
        }

        $comparacionFecha = $izquierda['creada_timestamp'] <=> $derecha['creada_timestamp'];

        return $comparacionFecha !== 0
            ? $comparacionFecha
            : strcmp($izquierda['id'], $derecha['id']);
    }

    /** @param  array<string, mixed>  $candidato */
    private function fueraPlanificador(array $candidato): bool
    {
        return $candidato['plan_tipo'] === 'recepcion_repaletizaje';
    }

    /**
     * @param  array<string, mixed>  $candidato
     * @param  array<int, string>|null  $camarasRollout
     */
    private function fueraRollout(array $candidato, ?array $camarasRollout): bool
    {
        if ($camarasRollout === null) {
            return false;
        }

        return $camarasRollout === []
            || $this->alguno(
                $candidato['camaras'],
                fn (string $camara): bool => ! in_array($camara, $camarasRollout, true),
            );
    }

    /**
     * @param  array<string, mixed>  $candidato
     * @param  array<string, string>  $recursosTomados
     */
    private function ocuparRecursos(array $candidato, array &$recursosTomados): void
    {
        foreach ($candidato['recursos'] as $recurso) {
            $recursosTomados[$recurso] = $candidato['id'];
        }
    }

    /** @param  array<int, string>  $valores */
    private function alguno(array $valores, callable $condicion): bool
    {
        foreach ($valores as $valor) {
            if ($condicion($valor)) {
                return true;
            }
        }

        return false;
    }
}
