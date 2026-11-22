<?php

namespace App\Services\Asistente;

use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;

/**
 * Intérprete sin IA: reconoce entidades y elige la consulta permitida cuyas palabras
 * clave mejor cubren la pregunta. Si ninguna alcanza el umbral, entrega las tres más
 * parecidas para que el usuario elija; nunca deja la pregunta sin salida.
 */
class InterpreteReglasAsistente
{
    public const UMBRAL = 0.34;

    private const PALABRAS_VACIAS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'y', 'o', 'en', 'por', 'para', 'con', 'que', 'cual', 'cuales',
        'cuanto', 'cuantos', 'cuanta', 'cuantas', 'como', 'me', 'mi', 'al', 'a', 'se', 'es', 'son', 'hay', 'dame', 'muestra',
        'muestrame', 'ver', 'quiero', 'saber', 'hoy', 'ayer', 'esta', 'este', 'semana', 'mes', 'pasada', 'pasado', 'temporada',
        'anterior', 'favor', 'porfa', 'total', 'todos', 'todas',
    ];

    public function __construct(
        private readonly CatalogoConsultasAsistente $catalogo,
        private readonly ReconocedorEntidades $reconocedor,
    ) {}

    /**
     * @return array{consulta: ?ConsultaAsistente, parametros: ParametrosConsulta, confianza: float,
     *     alternativas: list<ConsultaAsistente>}
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
        );
        $permitidas = $this->catalogo->disponiblesPara($usuario);

        if ($entidades['codigo'] !== null) {
            $expediente = $this->primera($permitidas, 'expediente-por-codigo');
            if ($expediente) {
                return ['consulta' => $expediente, 'parametros' => $parametros, 'confianza' => 1.0, 'alternativas' => []];
            }
        }

        $significativas = array_values(array_filter(
            $entidades['palabras'],
            fn (string $palabra): bool => ! in_array($palabra, self::PALABRAS_VACIAS, true) && ! preg_match('/^[\d\-\/]+$/', $palabra),
        ));
        $puntajes = [];
        foreach ($permitidas as $indice => $consulta) {
            $claves = $consulta->palabrasClave();
            $aciertos = count(array_filter($significativas, fn (string $palabra): bool => $this->coincide($palabra, $claves)));
            $puntajes[$indice] = $aciertos / max(1, count($significativas));
        }
        arsort($puntajes);
        $orden = array_keys($puntajes);

        $mejor = $orden[0] ?? null;
        if ($mejor !== null && $puntajes[$mejor] >= self::UMBRAL) {
            return [
                'consulta' => $permitidas[$mejor],
                'parametros' => $parametros,
                'confianza' => round($puntajes[$mejor], 3),
                'alternativas' => [],
            ];
        }

        $conPuntaje = array_values(array_filter($orden, fn (int $i): bool => $puntajes[$i] > 0));
        $elegidas = $conPuntaje !== [] ? $conPuntaje : array_keys($permitidas);

        return [
            'consulta' => null,
            'parametros' => $parametros,
            'confianza' => $mejor !== null ? round($puntajes[$mejor], 3) : 0.0,
            'alternativas' => array_values(array_map(fn (int $i): ConsultaAsistente => $permitidas[$i], array_slice($elegidas, 0, 3))),
        ];
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
