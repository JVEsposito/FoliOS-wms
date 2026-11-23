<?php

namespace App\Services\Asistente;

use App\Models\PreguntaAsistente;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Compuerta de la fase B (docs/ASISTENTE_ESTUDIO_HAIKU.md): Haiku se conecta solo si,
 * con la fase A corregida y las consultas nuevas, más del 15 % de las preguntas reales
 * siguen terminando en sugerencias, y el banco no tiene respuestas falsas.
 *
 * Mide el registro de preguntas de dos formas: como quedó registrado y reinterpretado
 * con el catálogo y las reglas de hoy, porque una pregunta que antes terminó en
 * sugerencias puede tener ya su consulta. Es de solo lectura: no ejecuta consultas ni
 * registra preguntas.
 */
class CompuertaAsistente
{
    /** Proporción de preguntas reales en sugerencias sobre la cual se justifica Haiku. */
    public const UMBRAL_SUGERENCIAS = 0.15;

    /** Menos preguntas que esto no alcanzan para decidir. */
    public const MINIMO_PREGUNTAS = 50;

    /** Tope de preguntas reinterpretadas por medición; las más recientes. */
    public const MAXIMO_REINTERPRETADAS = 2000;

    public function __construct(
        private readonly InterpreteReglasAsistente $interprete,
        private readonly EvaluadorBancoAsistente $banco,
    ) {}

    /**
     * @return array{desde: string, hasta: string, registro: array<string, mixed>, reinterpretado: array<string, mixed>,
     *     sin_respuesta: list<array{texto: string, veces: int}>, banco: array<string, int>, decision: string, motivo: string}
     */
    public function medir(User $evaluador, int $dias = 7): array
    {
        $hasta = CarbonImmutable::now();
        $desde = $hasta->subDays(max(1, $dias));
        $registro = PreguntaAsistente::query()
            ->where('interprete', 'reglas')
            ->where('created_at', '>=', $desde)->where('created_at', '<', $hasta);

        $porEstado = (clone $registro)->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado')->map(fn ($n): int => (int) $n);
        $total = (int) $porEstado->sum();
        $usuarios = (int) (clone $registro)->distinct()->count('user_id');

        $reinterpretadas = 0;
        $sinConsulta = 0;
        $textos = [];
        $usuariosCache = [];
        (clone $registro)->with('usuario')->latest('created_at')->limit(self::MAXIMO_REINTERPRETADAS)->get(['id', 'user_id', 'texto'])
            ->each(function (PreguntaAsistente $pregunta) use (&$reinterpretadas, &$sinConsulta, &$textos, &$usuariosCache): void {
                $usuario = $usuariosCache[$pregunta->user_id] ??= $pregunta->usuario;
                if (! $usuario instanceof User || ! $usuario->activo) {
                    return;
                }
                $reinterpretadas++;
                if ($this->interprete->interpretar($usuario, $pregunta->texto)['consulta'] === null) {
                    $sinConsulta++;
                    $clave = TextoNormalizado::de($pregunta->texto);
                    $textos[$clave] ??= ['texto' => $pregunta->texto, 'veces' => 0];
                    $textos[$clave]['veces']++;
                }
            });
        usort($textos, fn (array $a, array $b): int => $b['veces'] <=> $a['veces']);

        $proporcionRegistro = $total > 0 ? ($porEstado['sugerencias'] ?? 0) / $total : 0.0;
        $proporcionActual = $reinterpretadas > 0 ? $sinConsulta / $reinterpretadas : 0.0;
        $banco = $this->banco->evaluar($evaluador);
        [$decision, $motivo] = $this->decidir($total, $proporcionActual, $banco['falsas']);

        return [
            'desde' => $desde->toIso8601String(),
            'hasta' => $hasta->toIso8601String(),
            'registro' => [
                'total' => $total,
                'respondidas' => $porEstado['respondida'] ?? 0,
                'sin_datos' => $porEstado['sin_datos'] ?? 0,
                'sugerencias' => $porEstado['sugerencias'] ?? 0,
                'proporcion_sugerencias' => round($proporcionRegistro, 3),
                'usuarios' => $usuarios,
            ],
            'reinterpretado' => [
                'total' => $reinterpretadas,
                'sin_consulta' => $sinConsulta,
                'proporcion_sugerencias' => round($proporcionActual, 3),
            ],
            'sin_respuesta' => array_slice(array_values($textos), 0, 20),
            'banco' => array_diff_key($banco, ['detalle' => true]),
            'decision' => $decision,
            'motivo' => $motivo,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function decidir(int $total, float $proporcion, int $falsasBanco): array
    {
        $umbral = (int) round(self::UMBRAL_SUGERENCIAS * 100);
        if ($falsasBanco > 0) {
            return ['corregir_reglas', "El banco tiene {$falsasBanco} respuestas falsas: hay que corregir las reglas antes de medir."];
        }
        if ($total < self::MINIMO_PREGUNTAS) {
            return ['datos_insuficientes', sprintf('Hay %d preguntas en el periodo; se necesitan al menos %d para decidir.', $total, self::MINIMO_PREGUNTAS)];
        }
        $porcentaje = number_format($proporcion * 100, 1, ',', '.');
        if ($proporcion > self::UMBRAL_SUGERENCIAS) {
            return ['conectar_haiku', "El {$porcentaje} % de las preguntas reales sigue terminando en sugerencias, sobre el umbral de {$umbral} %."];
        }

        return ['seguir_sin_ia', "Solo el {$porcentaje} % de las preguntas reales termina en sugerencias, bajo el umbral de {$umbral} %: conviene sumar consultas antes que Haiku."];
    }
}
