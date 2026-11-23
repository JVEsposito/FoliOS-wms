<?php

namespace App\Services\Asistente;

use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;

/**
 * Intérprete sin IA: reconoce entidades y elige la consulta permitida cuyas palabras
 * clave cubren la pregunta. Responde solo cuando está seguro: una consulta clara, sin
 * empate, que explique la mayoría de las palabras que no son periodos ni nombres.
 * Si no, ofrece las consultas más parecidas; si ninguna se parece, no inventa
 * sugerencias. Una respuesta equivocada es peor que una repregunta.
 */
class InterpreteReglasAsistente
{
    /** Proporción mínima de palabras significativas que la consulta debe explicar. */
    public const UMBRAL = 0.6;

    private const PALABRAS_VACIAS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'y', 'o', 'en', 'por', 'para', 'con', 'que', 'cual', 'cuales',
        'cuanto', 'cuantos', 'cuanta', 'cuantas', 'como', 'me', 'mi', 'al', 'a', 'se', 'es', 'son', 'hay', 'dame', 'muestra',
        'muestrame', 'ver', 'quiero', 'saber', 'hoy', 'ayer', 'anteayer', 'anoche', 'manana', 'esta', 'este', 'semana', 'mes',
        'pasada', 'pasado', 'temporada', 'anterior', 'favor', 'porfa', 'total', 'todos', 'todas', 'toda', 'turno', 'b',
        'noche', 'dia', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo', 'curso', 'actual', 'ahora',
        'tiene', 'tienen', 'tenemos', 'tengo', 'lleva', 'llevan', 'llevamos', 'queda', 'quedan', 'quedaron', 'viene',
        'vienen', 'va', 'van', 'vamos', 'sigue', 'siguen', 'hizo', 'hicieron', 'hubo', 'fue', 'fueron', 'estan', 'estuvo',
        'estuvieron', 'ya', 'todavia', 'aun', 'nos', 'les', 'le', 'lo', 'su', 'sus', 'mas', 'menos', 'hasta', 'desde', 'entre',
        'cliente', 'clientes', 'dime', 'necesito', 'puedes', 'podrias', 'cuando', 'n', 'no',
    ];

    public function __construct(
        private readonly CatalogoConsultasAsistente $catalogo,
        private readonly ReconocedorEntidades $reconocedor,
    ) {}

    /**
     * @return array{consulta: ?ConsultaAsistente, parametros: ParametrosConsulta, confianza: float,
     *     alternativas: list<ConsultaAsistente>, entidades: array<string, mixed>, motivo: ?string}
     */
    public function interpretar(User $usuario, string $texto): array
    {
        $entidades = $this->reconocedor->reconocer($texto);
        $parametros = new ParametrosConsulta(
            desde: $entidades['desde'],
            hasta: $entidades['hasta'],
            clienteId: $entidades['cliente_id'],
            csgId: $entidades['csg_id'],
            especieId: $entidades['especie_id'],
            codigo: $entidades['codigo'],
            temporadaAnterior: $entidades['temporada_anterior'],
            turno: $entidades['turno'],
            etiquetaPeriodo: $entidades['periodo'],
            periodoExplicito: $entidades['periodo_explicito'],
            agrupar: $entidades['agrupar'],
        );
        $permitidas = $this->catalogo->disponiblesPara($usuario);
        $base = ['parametros' => $parametros, 'entidades' => $entidades, 'motivo' => null];

        if ($entidades['codigo'] !== null) {
            $expediente = $this->primera($permitidas, 'expediente-por-codigo');
            if ($expediente) {
                return [...$base, 'consulta' => $expediente, 'confianza' => 1.0, 'alternativas' => []];
            }
        }

        $significativas = array_values(array_filter(
            $entidades['palabras'],
            fn (string $palabra): bool => ! in_array($palabra, self::PALABRAS_VACIAS, true)
                && ! in_array($palabra, $entidades['consumidas'], true)
                && ! preg_match('/^[\d\-\/]+$/', $palabra),
        ));
        $puntajes = [];
        foreach ($permitidas as $indice => $consulta) {
            $claves = $consulta->palabrasClave();
            $secundarias = $consulta->palabrasSecundarias();
            $aciertos = 0.0;
            foreach ($significativas as $palabra) {
                $aciertos += $this->coincide($palabra, $claves) ? 1.0 : ($this->coincide($palabra, $secundarias) ? 0.5 : 0.0);
            }
            $puntajes[$indice] = $aciertos / max(1, count($significativas));
        }
        arsort($puntajes);
        $orden = array_keys($puntajes);
        $mejor = $orden[0] ?? null;
        $segundo = $orden[1] ?? null;
        $confianza = $mejor !== null ? round($puntajes[$mejor], 3) : 0.0;
        $empate = $segundo !== null && $puntajes[$segundo] === $puntajes[$mejor];

        $motivo = null;
        if ($mejor !== null && $puntajes[$mejor] >= self::UMBRAL && ! $empate) {
            $motivo = $this->fueraDeAlcance(TextoNormalizado::de($texto), $significativas, $permitidas[$mejor], $parametros);
            if ($motivo === null) {
                return [...$base, 'consulta' => $permitidas[$mejor], 'confianza' => $confianza, 'alternativas' => []];
            }
        }

        // Sugerencias solo entre las que comparten alguna palabra: nunca las primeras del catálogo por defecto.
        $parecidas = array_values(array_filter($orden, fn (int $i): bool => $puntajes[$i] > 0));

        return [
            ...$base,
            'motivo' => $motivo,
            'consulta' => null,
            'confianza' => $confianza,
            'alternativas' => array_values(array_map(fn (int $i): ConsultaAsistente => $permitidas[$i], array_slice($parecidas, 0, 3))),
        ];
    }

    /**
     * Preguntas que la consulta elegida no puede responder con verdad aunque sus palabras
     * coincidan: la fase A pregunta en vez de dar una cifra parecida.
     *
     * @param  list<string>  $significativas
     */
    private function fueraDeAlcance(string $texto, array $significativas, ConsultaAsistente $consulta, ParametrosConsulta $parametros): ?string
    {
        if (preg_match('/\b(contra|versus|vs|compara|comparar|comparado|comparada|comparados|comparadas)\b|\b(que|cual|cuales) (dia|dias|turno|turnos|semana|semanas|mes|meses|hora|horas)\b/', $texto)) {
            return 'Todavía no comparo periodos ni agrupo por día o turno. Puedo mostrar cada periodo por separado:';
        }
        if (preg_match('/\b(mas|menos) de \d+\b/', $texto)) {
            return 'Todavía no filtro por «más de» o «menos de». Estas consultas muestran el detalle completo:';
        }
        // «¿Cómo viene el CSG…?» nombra un tema pero no la cifra: es una evaluación.
        if (count($significativas) <= 1 && preg_match('/\bcomo (viene|vienen|va|van|vamos|anda|andan|estuvo|estuvieron)\b/', $texto)) {
            return 'Esa pregunta pide una evaluación. Elige qué cifra quieres ver:';
        }
        if (! in_array('periodo', $consulta->filtros(), true) && ($parametros->temporadaAnterior
            || ($parametros->periodoExplicito && $parametros->jornadas()[1] < app(PeriodoOperativo::class)->jornada()))) {
            return 'Esa consulta muestra lo que hay ahora, no lo de otro día. ¿Te sirve alguna de estas?';
        }

        return null;
    }

    /** @param  list<string>  $claves */
    private function coincide(string $palabra, array $claves): bool
    {
        if (in_array($palabra, $claves, true)) {
            return true;
        }
        $singular = preg_replace('/(es|s)$/', '', $palabra);
        foreach ($claves as $clave) {
            if ($singular === $clave || (strlen($palabra) >= 6 && strlen($clave) >= 6 && levenshtein($palabra, $clave) <= 1)) {
                return true;
            }
            // Misma raíz de al menos cinco letras: «demoraron» y «demora», «recibimos» y «recibidos».
            if (strlen($palabra) >= 5 && strlen($clave) >= 5 && strncmp($palabra, $clave, 5) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<ConsultaAsistente>  $consultas */
    private function primera(array $consultas, string $clave): ?ConsultaAsistente
    {
        foreach ($consultas as $consulta) {
            if ($consulta->clave() === $clave) {
                return $consulta;
            }
        }

        return null;
    }
}
