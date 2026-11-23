<?php

namespace App\Services\Asistente;

use App\Models\User;
use App\Services\Asistente\Haiku\InterpreteHaiku;

/**
 * Corre el intérprete sobre el banco de preguntas de la planta
 * (resources/asistente/banco-preguntas.json) sin ejecutar consultas ni registrar nada.
 *
 * Cada pregunta termina en una de cuatro clases:
 * - correcta: eligió la consulta que el banco espera hoy o la consulta objetivo;
 * - pregunta: no eligió consulta y el banco tampoco lo espera;
 * - pregunta_de_mas: no eligió consulta, pero el banco espera que responda (no es falsa);
 * - falsa: eligió otra consulta. Es la única falla grave.
 *
 * La prueba de CI revisa además periodos y filtros con datos de ejemplo; aquí, con los
 * datos reales de la planta, solo se compara la consulta elegida.
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
     *
     * @return array{total: int, correctas: int, preguntas: int, preguntas_de_mas: int, falsas: int,
     *     llamadas_haiku: int, costo_usd: float, tokens_entrada: int, tokens_salida: int,
     *     detalle: list<array{n: int, pregunta: string, esperada: ?string, elegida: ?string, clase: string, interprete: string}>}
     */
    public function evaluar(User $usuario, bool $conHaiku = false): array
    {
        $detalle = [];
        $llamadas = 0;
        $costo = 0.0;
        $entrada = 0;
        $salida = 0;
        foreach ($this->preguntas() as $pregunta) {
            $reglas = $this->interprete->interpretar($usuario, $pregunta['pregunta']);
            $elegida = $reglas['consulta']?->clave();
            $interprete = 'reglas';
            if ($elegida === null && $conHaiku) {
                $haiku = $this->haiku->interpretar($usuario, $pregunta['pregunta'], $reglas['entidades']);
                $interprete = 'haiku:'.$haiku['tipo'];
                $elegida = $haiku['tipo'] === InterpreteHaiku::CONSULTA ? $haiku['consulta']->clave() : null;
                if ($haiku['llamada'] !== null) {
                    $llamadas++;
                    $costo += $haiku['llamada']->costo_usd;
                    $entrada += $haiku['llamada']->tokens_entrada + $haiku['llamada']->tokens_cache_lectura + $haiku['llamada']->tokens_cache_escritura;
                    $salida += $haiku['llamada']->tokens_salida;
                }
            }
            $esperadas = array_values(array_filter([$pregunta['responde_hoy'] ?? null, $pregunta['consulta_objetivo'] ?? null]));
            $clase = match (true) {
                $elegida !== null && in_array($elegida, $esperadas, true) => 'correcta',
                $elegida !== null => 'falsa',
                ($pregunta['responde_hoy'] ?? null) !== null => 'pregunta_de_mas',
                default => 'pregunta',
            };
            $detalle[] = [
                'n' => (int) $pregunta['n'],
                'pregunta' => (string) $pregunta['pregunta'],
                'esperada' => $pregunta['responde_hoy'] ?? null,
                'elegida' => $elegida,
                'clase' => $clase,
                'interprete' => $interprete,
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
            'costo_usd' => round($costo, 6),
            'tokens_entrada' => $entrada,
            'tokens_salida' => $salida,
            'detalle' => $detalle,
        ];
    }
}
