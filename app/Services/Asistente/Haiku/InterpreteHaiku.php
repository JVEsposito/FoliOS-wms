<?php

namespace App\Services\Asistente\Haiku;

use App\Models\LlamadaIaAsistente;
use App\Models\User;
use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\ParametrosConsulta;

/**
 * Haiku como intérprete: recibe la pregunta con fichas y el catálogo permitido, y
 * contesta solo con una herramienta. FoliOS valida esa elección; nunca ejecuta lo que
 * no pudo comprobar. Ante cualquier falla, el asistente sigue sin IA.
 */
class InterpreteHaiku
{
    public const CONSULTA = 'consulta';

    public const ACLARACION = 'aclaracion';

    public const SIN_CONSULTA = 'sin_consulta';

    public const INVALIDA = 'invalida';

    /** La consulta más cercana no cubre toda la pregunta: se ofrece sin ejecutarla. */
    public const PARCIAL = 'parcial';

    /** Condiciones sin cubrir que se muestran, como máximo, y largo de cada una. */
    private const MAXIMO_SIN_CUBRIR = 4;

    private const LARGO_CONDICION = 60;

    public const NO_DISPONIBLE = 'no_disponible';

    public const SENSIBLE = 'sensible';

    public function __construct(
        private readonly ClienteAnthropic $cliente,
        private readonly HerramientasHaiku $herramientas,
        private readonly FichasAsistente $fichas,
        private readonly FiltroSensibleAsistente $sensible,
        private readonly ValidadorLlamadaHaiku $validador,
        private readonly PresupuestoHaiku $presupuesto,
        private readonly CatalogoConsultasAsistente $catalogo,
    ) {}

    /**
     * @param  array<string, mixed>  $entidades  lo que reconocieron las reglas
     * @return array{tipo: string, consulta: ?ConsultaAsistente, parametros: ?ParametrosConsulta,
     *     opciones: list<ConsultaAsistente>, motivo: ?string, sin_cubrir: list<string>, pide: ?string, llamada: ?LlamadaIaAsistente}
     */
    public function interpretar(User $usuario, string $texto, array $entidades): array
    {
        $vacio = ['consulta' => null, 'parametros' => null, 'opciones' => [], 'motivo' => null, 'sin_cubrir' => [], 'pide' => null, 'llamada' => null];
        if ($this->sensible->esSensible($texto)) {
            return [...$vacio, 'tipo' => self::SENSIBLE, 'motivo' => self::SENSIBLE];
        }
        $permitidas = $this->catalogo->disponiblesPara($usuario);
        $impedimento = $permitidas === [] ? PresupuestoHaiku::SIN_CLAVE : $this->presupuesto->impedimento($usuario);
        if ($impedimento !== null) {
            return [...$vacio, 'tipo' => self::NO_DISPONIBLE, 'motivo' => $impedimento];
        }

        $preparada = $this->fichas->preparar($texto, $entidades);
        $definicion = $this->herramientas->definir($permitidas);
        $inicio = hrtime(true);
        try {
            $respuesta = $this->cliente->mensajes([
                'model' => $this->cliente->modelo(),
                'max_tokens' => (int) config('asistente.anthropic.max_tokens'),
                'system' => $definicion['system'],
                'tools' => $definicion['tools'],
                'tool_choice' => ['type' => 'any'],
                'messages' => [['role' => 'user', 'content' => $this->herramientas->mensaje($preparada['texto'])]],
            ]);
        } catch (FallaHaiku $falla) {
            $llamada = $this->presupuesto->registrar($usuario, LlamadaIaAsistente::ERROR, [], $this->ms($inicio), detalle: $falla->motivo.': '.$falla->getMessage());

            return [...$vacio, 'tipo' => self::NO_DISPONIBLE, 'motivo' => $falla->motivo, 'llamada' => $llamada];
        }

        $uso = (array) ($respuesta['usage'] ?? []);
        $bloque = collect($respuesta['content'] ?? [])->first(fn ($b): bool => is_array($b) && ($b['type'] ?? null) === 'tool_use');
        $herramienta = is_array($bloque) ? (string) ($bloque['name'] ?? '') : '';
        $entrada = is_array($bloque) ? (array) ($bloque['input'] ?? []) : [];
        $registrar = fn (string $estado, ?string $consulta = null, ?string $detalle = null): LlamadaIaAsistente => $this->presupuesto
            ->registrar($usuario, $estado, $uso, $this->ms($inicio), $herramienta ?: null, $consulta, $detalle);

        if ($herramienta === HerramientasHaiku::CONSULTAR) {
            $pide = $this->condicion($entrada['pide'] ?? '', $preparada['fichas'], 200) ?: null;
            $validada = $this->validador->validar($entrada, $permitidas, $preparada['fichas']);
            if (is_string($validada)) {
                $elegida = collect($permitidas)->first(fn (ConsultaAsistente $c): bool => $c->clave() === ($entrada['consulta'] ?? null));

                return [...$vacio, 'tipo' => self::INVALIDA, 'motivo' => $validada, 'opciones' => $elegida ? [$elegida] : [], 'pide' => $pide,
                    'llamada' => $registrar(LlamadaIaAsistente::OK, $entrada['consulta'] ?? null, 'rechazada: '.$validada)];
            }

            // Lo que Haiku dice que falta va entre comillas, con las palabras del usuario; lo que FoliOS detectó, tal cual.
            $sinCubrir = array_values(array_unique([
                ...array_map(fn (string $c): string => "«{$c}»", $this->sinCubrir($entrada['sin_cubrir'] ?? [], $preparada['fichas'])),
                ...$validada['sin_cubrir'],
            ]));
            if ($sinCubrir !== []) {
                // El registro guarda cuántas condiciones faltaron, nunca el texto de la pregunta.
                return [...$vacio, 'tipo' => self::PARCIAL, 'consulta' => $validada['consulta'], 'opciones' => [$validada['consulta']],
                    'sin_cubrir' => $sinCubrir, 'pide' => $pide,
                    'llamada' => $registrar(LlamadaIaAsistente::OK, $validada['consulta']->clave(), 'parcial: '.count($sinCubrir).' sin cubrir')];
            }

            return [...$vacio, 'tipo' => self::CONSULTA, 'consulta' => $validada['consulta'], 'parametros' => $validada['parametros'], 'pide' => $pide,
                'llamada' => $registrar(LlamadaIaAsistente::OK, $validada['consulta']->clave())];
        }
        if ($herramienta === HerramientasHaiku::ACLARAR) {
            $claves = array_column(array_filter((array) ($entrada['opciones'] ?? []), 'is_array'), 'consulta');
            $opciones = array_values(array_filter($permitidas, fn (ConsultaAsistente $c): bool => in_array($c->clave(), $claves, true)));
            usort($opciones, fn ($a, $b): int => array_search($a->clave(), $claves, true) <=> array_search($b->clave(), $claves, true));

            return [...$vacio, 'tipo' => self::ACLARACION, 'opciones' => array_slice($opciones, 0, 4), 'llamada' => $registrar(LlamadaIaAsistente::OK)];
        }
        if ($herramienta === HerramientasHaiku::SIN_CONSULTA) {
            $motivo = (string) ($entrada['motivo'] ?? 'dato_no_disponible');

            return [...$vacio, 'tipo' => self::SIN_CONSULTA, 'motivo' => $motivo, 'llamada' => $registrar(LlamadaIaAsistente::OK, detalle: $motivo)];
        }

        $llamada = $registrar(LlamadaIaAsistente::ERROR, detalle: 'respuesta sin herramienta');

        return [...$vacio, 'tipo' => self::NO_DISPONIBLE, 'motivo' => FallaHaiku::RESPUESTA, 'llamada' => $llamada];
    }

    /**
     * Condiciones que Haiku dejó sin cubrir, limpias y con las fichas vueltas a su nombre.
     *
     * @param  array<string, array{tipo: string, id: string, etiqueta: string}>  $fichas
     * @return list<string>
     */
    private function sinCubrir(mixed $valor, array $fichas): array
    {
        $condiciones = array_map(fn (mixed $c): string => $this->condicion($c, $fichas, self::LARGO_CONDICION), is_array($valor) ? $valor : []);

        return array_slice(array_values(array_unique(array_filter($condiciones, fn (string $c): bool => $c !== ''))), 0, self::MAXIMO_SIN_CUBRIR);
    }

    /** @param  array<string, array{tipo: string, id: string, etiqueta: string}>  $fichas */
    private function condicion(mixed $valor, array $fichas, int $largo): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', is_string($valor) ? $valor : '') ?? '', " «»\"'.,;:");
        $texto = preg_replace_callback('/\[?\b((?:CLIENTE|CSG)_\d+)\b\]?/', fn (array $m): string => $fichas[$m[1]]['etiqueta'] ?? $m[0], $texto) ?? $texto;

        return mb_strimwidth($texto, 0, $largo, '…');
    }

    private function ms(int|float $inicio): int
    {
        return (int) round((hrtime(true) - $inicio) / 1_000_000);
    }
}
