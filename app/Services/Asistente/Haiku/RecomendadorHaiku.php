<?php

namespace App\Services\Asistente\Haiku;

use App\Models\LlamadaIaAsistente;
use App\Models\RecomendacionAsistente;
use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Recomendaciones a pedido (fase B, PR 8). Solo existen si el usuario las pide y siempre
 * llevan el aviso fijo de FoliOS. Haiku recibe solo el resultado que el usuario ya vio,
 * con fichas en vez de clientes y sin texto libre, más las capacidades que vienen al
 * caso. Cada número del texto debe estar en esos datos; si no, no se muestra.
 */
class RecomendadorHaiku
{
    public const AVISO = 'Solo es una recomendación de Haiku, basada en los datos de planta y las capacidades registradas en FoliOS. No es una instrucción: confírmala antes de actuar.';

    /** Frase que Haiku debe devolver en su campo obligatorio. */
    public const RECONOCIMIENTO = 'Solo es una recomendación basada en los datos de planta y sus capacidades.';

    private const HERRAMIENTA = 'recomendar';

    /** Columnas de texto que pueden salir: estados e instalaciones, nunca personas ni texto libre. */
    private const TEXTO_PERMITIDO = ['tunel', 'proceso', 'estado', 'etapa', 'resultado', 'tipo', 'jornada', 'envase', 'especie', 'regla', 'sentido', 'codigo', 'carga', 'recepcion', 'folio'];

    private const MAXIMO_FILAS = 30;

    private const SISTEMA = <<<'TXT'
Eres el asistente de FoliOS en una planta frutícola con frigorífico. El usuario pidió una recomendación sobre el resultado de una consulta que ya vio. Responde con la herramienta `recomendar`.

Reglas:
1. Usa solo los datos y capacidades que vienen en el mensaje. No supongas datos que no están.
2. Cada número que escribas debe aparecer tal cual en los datos o en las capacidades. No calcules porcentajes, promedios ni diferencias nuevas: FoliOS no podría verificarlos y descartaría la recomendación.
3. Máximo tres frases, concretas y operativas, en español neutro.
4. Es solo lectura: sugiere qué revisar o priorizar; nunca digas que hiciste algo ni des órdenes.
5. Si los datos no alcanzan para recomendar, dilo en una frase.
6. Los clientes van como fichas (CLIENTE_1); no inventes nombres.
TXT;

    public function __construct(
        private readonly ClienteAnthropic $cliente,
        private readonly PresupuestoHaiku $presupuesto,
    ) {}

    /** @return array{estado: string, aviso: string, texto: ?string, basada_en: list<string>, motivo: ?string} */
    public function recomendar(User $usuario, ConsultaAsistente $consulta, ResultadoConsulta $resultado): array
    {
        $respuesta = fn (string $estado, ?string $texto = null, array $basadaEn = [], ?string $motivo = null): array => [
            'estado' => $estado, 'aviso' => self::AVISO, 'texto' => $texto, 'basada_en' => $basadaEn, 'motivo' => $motivo,
        ];
        if (! $consulta->admiteRecomendacion() || $resultado->estado !== ResultadoConsulta::RESPONDIDA) {
            return $respuesta(RecomendacionAsistente::NO_DISPONIBLE, motivo: 'Esta respuesta no admite recomendaciones.');
        }
        $impedimento = $this->presupuesto->impedimento($usuario);
        if ($impedimento !== null) {
            return $respuesta(RecomendacionAsistente::NO_DISPONIBLE, motivo: match ($impedimento) {
                PresupuestoHaiku::TOPE_MENSUAL => 'Haiku llegó al tope de gasto del mes.',
                PresupuestoHaiku::LIMITE_DIARIO => 'Llegaste a tu límite diario de preguntas con Haiku.',
                PresupuestoHaiku::FUERA_DEL_PILOTO => 'Las recomendaciones están en piloto y tu usuario no participa.',
                default => 'Haiku no está configurado en el servidor.',
            });
        }

        $datos = $this->datos($consulta, $resultado);
        $inicio = hrtime(true);
        $ms = fn (): int => (int) round((hrtime(true) - $inicio) / 1_000_000);
        try {
            $mensaje = $this->cliente->mensajes([
                'model' => $this->cliente->modelo(),
                'max_tokens' => 500,
                'system' => [['type' => 'text', 'text' => self::SISTEMA]],
                'tools' => [$this->herramienta()],
                'tool_choice' => ['type' => 'tool', 'name' => self::HERRAMIENTA],
                'messages' => [['role' => 'user', 'content' => "Datos de FoliOS en JSON:\n".json_encode($datos, JSON_UNESCAPED_UNICODE)]],
            ]);
        } catch (FallaHaiku $falla) {
            $llamada = $this->presupuesto->registrar($usuario, LlamadaIaAsistente::ERROR, [], $ms(), self::HERRAMIENTA, $consulta->clave(), $falla->motivo.': '.$falla->getMessage());
            $this->registrar($usuario, $consulta, $datos, null, RecomendacionAsistente::NO_DISPONIBLE, 'Haiku no respondió', $llamada);

            return $respuesta(RecomendacionAsistente::NO_DISPONIBLE, motivo: 'Haiku no está disponible ahora. Intenta más tarde.');
        }

        $bloque = collect($mensaje['content'] ?? [])->first(fn ($b): bool => is_array($b) && ($b['type'] ?? null) === 'tool_use' && ($b['name'] ?? null) === self::HERRAMIENTA);
        $entrada = is_array($bloque) ? (array) ($bloque['input'] ?? []) : [];
        $texto = trim((string) ($entrada['recomendacion'] ?? ''));
        $basadaEn = array_values(array_filter(array_map('strval', (array) ($entrada['basada_en'] ?? []))));
        $llamada = $this->presupuesto->registrar($usuario, $texto === '' ? LlamadaIaAsistente::ERROR : LlamadaIaAsistente::OK, (array) ($mensaje['usage'] ?? []), $ms(), self::HERRAMIENTA, $consulta->clave());

        $motivo = match (true) {
            $texto === '' => 'Haiku no devolvió una recomendación.',
            ($entrada['reconocimiento'] ?? null) !== self::RECONOCIMIENTO => 'Haiku no reconoció que es solo una recomendación.',
            ($ajenos = $this->numerosAjenos($texto, $datos)) !== [] => 'Tiene cifras que no están en los datos: '.implode(', ', $ajenos).'.',
            default => null,
        };
        $this->registrar($usuario, $consulta, $datos, $texto ?: null, $motivo === null ? RecomendacionAsistente::MOSTRADA : RecomendacionAsistente::RECHAZADA, $motivo, $llamada);

        return $motivo === null
            ? $respuesta(RecomendacionAsistente::MOSTRADA, $texto, $basadaEn)
            : $respuesta(RecomendacionAsistente::RECHAZADA, motivo: 'FoliOS no pudo verificar la recomendación y no la muestra. '.$motivo);
    }

    /**
     * Lo único que sale: título, cifras, filas con columnas permitidas y capacidades.
     *
     * @return array<string, mixed>
     */
    public function datos(ConsultaAsistente $consulta, ResultadoConsulta $resultado): array
    {
        $columnas = collect($resultado->columnas)->filter(fn (array $c): bool => in_array($c['tipo'] ?? 'texto', ['numero', 'codigo', 'folio'], true)
            || in_array($c['clave'], self::TEXTO_PERMITIDO, true) || $c['clave'] === 'cliente');
        $fichas = [];
        $filas = collect($resultado->filas)->take(self::MAXIMO_FILAS)->map(function (array $fila) use ($columnas, &$fichas): array {
            $salida = [];
            foreach ($columnas as $columna) {
                $valor = $fila[$columna['clave']] ?? null;
                if ($columna['clave'] === 'cliente' && $valor !== null) {
                    $valor = $fichas[(string) $valor] ??= 'CLIENTE_'.(count($fichas) + 1);
                }
                $salida[$columna['titulo']] = $valor;
            }

            return $salida;
        })->all();

        return [
            'consulta' => $consulta->titulo(),
            'descripcion' => $consulta->descripcion(),
            'cifras' => array_map(fn (array $c): array => ['nombre' => $c['etiqueta'], 'valor' => $c['valor'], 'unidad' => $c['unidad'] ?? null], $resultado->cifras),
            'filas' => $filas,
            'filas_omitidas' => max(0, count($resultado->filas) - self::MAXIMO_FILAS),
            'capacidades' => $consulta->capacidades(),
        ];
    }

    /** @return list<string> números del texto que no aparecen en los datos enviados */
    public function numerosAjenos(string $texto, array $datos): array
    {
        $permitidos = [];
        array_walk_recursive($datos, function ($valor) use (&$permitidos): void {
            if (is_int($valor) || is_float($valor)) {
                $permitidos[self::canonico((float) $valor)] = true;
            } elseif (is_string($valor) && preg_match_all('/\d+(?:[.,]\d+)*/', $valor, $m)) {
                foreach ($m[0] as $numero) {
                    foreach (self::lecturas($numero) as $lectura) {
                        $permitidos[$lectura] = true;
                    }
                }
            }
        });
        preg_match_all('/\d+(?:[.,]\d+)*/', $texto, $encontrados);

        return array_values(array_unique(array_filter(
            $encontrados[0],
            fn (string $numero): bool => array_filter(self::lecturas($numero), fn (string $l): bool => isset($permitidos[$l])) === [],
        )));
    }

    /** Lecturas posibles de un número escrito: «9,5», «1.234» o «1.234,5». @return list<string> */
    private static function lecturas(string $numero): array
    {
        $candidatos = [
            str_replace(',', '.', $numero),
            str_replace(',', '.', str_replace('.', '', $numero)),
            str_replace(',', '', $numero),
        ];

        return array_values(array_unique(array_map(
            fn (string $c): string => self::canonico((float) $c),
            array_filter($candidatos, 'is_numeric'),
        )));
    }

    private static function canonico(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.');
    }

    private function herramienta(): array
    {
        return [
            'name' => self::HERRAMIENTA,
            'description' => 'Entrega una recomendación breve basada solo en los datos y capacidades recibidos.',
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'recomendacion' => ['type' => 'string', 'description' => 'Máximo tres frases. Cada número debe estar en los datos.'],
                    'basada_en' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de las cifras, columnas o capacidades usadas.'],
                    'reconocimiento' => ['type' => 'string', 'enum' => [self::RECONOCIMIENTO], 'description' => 'Siempre esta frase.'],
                ],
                'required' => ['recomendacion', 'basada_en', 'reconocimiento'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function registrar(User $usuario, ConsultaAsistente $consulta, array $datos, ?string $respuesta, string $estado, ?string $motivo, ?LlamadaIaAsistente $llamada): void
    {
        RecomendacionAsistente::query()->create([
            'user_id' => $usuario->id,
            'llamada_ia_id' => $llamada?->id,
            'consulta' => $consulta->clave(),
            'datos_enviados' => $datos,
            'respuesta' => $respuesta,
            'estado' => $estado,
            'motivo' => $motivo === null ? null : mb_substr($motivo, 0, 300),
            'created_at' => now(),
        ]);
    }
}
