<?php

namespace App\Services\Asistente;

use App\Models\User;

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
    public function __construct(private readonly InterpreteReglasAsistente $interprete) {}

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
     * @return array{total: int, correctas: int, preguntas: int, preguntas_de_mas: int, falsas: int,
     *     detalle: list<array{n: int, pregunta: string, esperada: ?string, elegida: ?string, clase: string}>}
     */
    public function evaluar(User $usuario): array
    {
        $detalle = [];
        foreach ($this->preguntas() as $pregunta) {
            $elegida = $this->interprete->interpretar($usuario, $pregunta['pregunta'])['consulta']?->clave();
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
            ];
        }
        $cuenta = fn (string $clase): int => count(array_filter($detalle, fn (array $d): bool => $d['clase'] === $clase));

        return [
            'total' => count($detalle),
            'correctas' => $cuenta('correcta'),
            'preguntas' => $cuenta('pregunta'),
            'preguntas_de_mas' => $cuenta('pregunta_de_mas'),
            'falsas' => $cuenta('falsa'),
            'detalle' => $detalle,
        ];
    }
}
