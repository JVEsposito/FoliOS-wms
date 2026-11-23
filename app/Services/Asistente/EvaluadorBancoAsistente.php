<?php

namespace App\Services\Asistente;

use App\Models\LlamadaIaAsistente;
use App\Models\User;
use App\Services\Asistente\Haiku\InterpreteHaiku;

/**
 * Corre el intérprete sobre el banco de preguntas de la planta
 * (resources/asistente/banco-preguntas.json) sin ejecutar consultas de negocio ni
 * registrar preguntas. En modo Haiku registra las llamadas, sus tokens y su costo.
 *
 * Cada pregunta termina en una de cuatro clases:
 * - correcta: eligió la consulta que el banco espera hoy o la consulta objetivo;
 * - pregunta: no eligió consulta y el banco tampoco lo espera;
 * - pregunta_de_mas: no eligió consulta, pero el banco espera que responda (no es falsa);
 * - falsa: eligió otra consulta. Es la única falla grave.
 *
 * La prueba de CI revisa además periodos y filtros con datos de ejemplo. La evaluación
 * cuenta también errores y preguntas sin API disponible: cero falsas no basta si la
 * API falló o la evaluación se cortó por los topes o el piloto.
 */
class EvaluadorBancoAsistente
{
    public function __construct(
        private readonly InterpreteReglasAsistente $interprete,
        private readonly InterpreteHaiku $haiku,
    ) {}

    public static function ruta(): string
    {
        return resource_path('asistente/banco-preguntas.json');
    }

    /** @return list<array<string, mixed>> */
    public function preguntas(): array
    {
        return json_decode((string) file_get_contents(self::ruta()), true, flags: JSON_THROW_ON_ERROR)['preguntas'];
    }

    /**
     * Con Haiku se evalúa como en el modo mixto: primero las reglas y, si no están
     * seguras, Haiku. Las llamadas son reales, cuentan en los topes y quedan registradas.
     * Sin $preguntas se usa el banco de la planta.
     *
     * @param  list<array<string, mixed>>|null  $preguntas
     * @return array{total: int, correctas: int, preguntas: int, preguntas_de_mas: int, falsas: int,
     *     llamadas_haiku: int, llamadas_exitosas_haiku: int, errores_haiku: int, no_disponibles_haiku: int,
     *     costo_usd: float, tokens_entrada: int, tokens_salida: int,
     *     detalle: list<array{n: int, pregunta: string, esperada: ?string, elegida: ?string, vista_elegida: ?string, clase: string, interprete: string, detalle: ?string}>}
     */
    public function evaluar(User $usuario, bool $conHaiku = false, ?array $preguntas = null): array
    {
        $detalle = [];
        $llamadas = 0;
        $costo = 0.0;
        $entrada = 0;
        $salida = 0;
        $exitosas = 0;
        $errores = 0;
        $noDisponibles = 0;
        foreach ($preguntas ?? $this->preguntas() as $pregunta) {
            $reglas = $this->interprete->interpretar($usuario, $pregunta['pregunta']);
            $elegida = $reglas['consulta']?->clave();
            $vista = $elegida !== null ? $reglas['parametros']->vista : null;
            $interprete = 'reglas';
            $explicacion = null;
            if ($elegida === null && $conHaiku) {
                $haiku = $this->haiku->interpretar($usuario, $pregunta['pregunta'], $reglas['entidades']);
                $interprete = 'haiku:'.$haiku['tipo'];
                if ($haiku['tipo'] === InterpreteHaiku::NO_DISPONIBLE) {
                    $noDisponibles++;
                }
                $elegida = $haiku['tipo'] === InterpreteHaiku::CONSULTA ? $haiku['consulta']->clave() : null;
                $vista = $haiku['tipo'] === InterpreteHaiku::CONSULTA ? $haiku['vista']?->clave : null;
                $explicacion = $this->explicar($haiku);
                if ($haiku['llamada'] !== null) {
                    $llamadas++;
                    $haiku['llamada']->estado === LlamadaIaAsistente::OK ? $exitosas++ : $errores++;
                    $costo += $haiku['llamada']->costo_usd;
                    $entrada += $haiku['llamada']->tokens_entrada + $haiku['llamada']->tokens_cache_lectura + $haiku['llamada']->tokens_cache_escritura;
                    $salida += $haiku['llamada']->tokens_salida;
                }
            }
            $esperadas = array_values(array_filter([$pregunta['responde_hoy'] ?? null, $pregunta['consulta_objetivo'] ?? null]));
            // En un tema con vistas, otra vista del mismo tema también es una respuesta falsa.
            $vistaCorrecta = ! isset($pregunta['vista']) || $pregunta['vista'] === $vista;
            $clase = match (true) {
                $elegida !== null && in_array($elegida, $esperadas, true) && $vistaCorrecta => 'correcta',
                $elegida !== null => 'falsa',
                ($pregunta['responde_hoy'] ?? null) !== null => 'pregunta_de_mas',
                default => 'pregunta',
            };
            $detalle[] = [
                'n' => (int) $pregunta['n'],
                'pregunta' => (string) $pregunta['pregunta'],
                'esperada' => $pregunta['responde_hoy'] ?? null,
                'elegida' => $elegida,
                'vista_elegida' => $vista,
                'clase' => $clase,
                'interprete' => $interprete,
                'detalle' => $explicacion,
            ];
        }
        $cuenta = fn (string $clase): int => count(array_filter($detalle, fn (array $d): bool => $d['clase'] === $clase));

        return [
            'total' => count($detalle),
            'correctas' => $cuenta('correcta'),
            'preguntas' => $cuenta('pregunta'),
            'preguntas_de_mas' => $cuenta('pregunta_de_mas'),
            'falsas' => $cuenta('falsa'),
            'llamadas_haiku' => $llamadas,
            'llamadas_exitosas_haiku' => $exitosas,
            'errores_haiku' => $errores,
            'no_disponibles_haiku' => $noDisponibles,
            'costo_usd' => round($costo, 6),
            'tokens_entrada' => $entrada,
            'tokens_salida' => $salida,
            'detalle' => $detalle,
        ];
    }

    /**
     * Por qué Haiku decidió así, para revisar el informe: lo que entendió que se pide, la
     * consulta que no cubrió todo y qué le faltó, o el motivo del rechazo.
     *
     * @param  array<string, mixed>  $haiku
     */
    private function explicar(array $haiku): ?string
    {
        return match ($haiku['tipo']) {
            InterpreteHaiku::CONSULTA => $haiku['pide'],
            InterpreteHaiku::PARCIAL => $haiku['consulta']->clave().' · sin cubrir: '.implode(', ', array_map(fn (string $c): string => trim($c, '«»'), $haiku['sin_cubrir'])),
            InterpreteHaiku::INVALIDA, InterpreteHaiku::SIN_CONSULTA, InterpreteHaiku::NO_DISPONIBLE => $haiku['motivo'],
            default => null,
        };
    }
}
