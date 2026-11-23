<?php

namespace App\Services\Asistente\Haiku;

use App\Models\EspecieValidacion;
use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\TextoNormalizado;
use App\Services\Temporadas\ServicioTemporadaActiva;

/**
 * Arma el pedido a Haiku: tres herramientas estrictas y un prompt de sistema fijo.
 *
 * La API admite hasta 20 herramientas estrictas y 24 parámetros opcionales por pedido,
 * así que en vez de una herramienta por consulta hay una sola, `consultar`, con la
 * consulta como enum y todos los parámetros obligatorios con un valor neutro. Las
 * claves y descripciones salen del catálogo permitido al usuario; ningún nombre de
 * cliente va en un esquema, porque los esquemas estrictos se guardan hasta 24 horas.
 */
class HerramientasHaiku
{
    public const CONSULTAR = 'consultar';

    public const ACLARAR = 'pedir_aclaracion';

    public const SIN_CONSULTA = 'sin_consulta';

    private const SISTEMA = <<<'TXT'
Eres el intérprete del asistente de FoliOS, el sistema de gestión de una planta frutícola con frigorífico. No respondes al usuario: eliges una herramienta. FoliOS valida tu elección, calcula las cifras y escribe la respuesta. Nunca escribes cifras.

Reglas:
1. Llama exactamente una herramienta.
   - `consultar` cuando una consulta del catálogo responde la pregunta tal como está escrita, o para ofrecer una consulta cercana declarando en `sin_cubrir` lo que le falta, sin ejecutarla.
   - `pedir_aclaracion` cuando dos a cuatro consultas podrían ser y la pregunta no permite elegir, o cuando la pregunta nombra un cliente, un CSG o un código que no llega como ficha.
   - `sin_consulta` cuando ninguna consulta la responde: otro tema, comparaciones entre periodos, pronósticos, recomendaciones, rankings o datos que el catálogo no tiene.
2. Nunca elijas una consulta que responde otra cosa solo porque se parece. Una repregunta es mejor que una cifra equivocada. Con `consultar`:
   - En `pide`, antes de elegir, escribe en pocas palabras la cifra o lista que pide la pregunta y cada condición que pone: un estado (pendiente, vacío, devuelto, anulado), una etapa o lugar, un destino o mercado, un tipo de envase o material, una agrupación.
   - En `sin_cubrir`, anota con las palabras de la pregunta cada condición que ni la consulta elegida ni sus parámetros cubren. El periodo, el cliente, el CSG y la especie que pusiste en sus parámetros sí están cubiertos.
   - Si `sin_cubrir` no queda vacía, FoliOS no ejecuta la consulta: ofrece la más cercana y dice qué le falta. Nunca dejes fuera una condición para que la consulta calce.
3. Los clientes y CSG reconocidos llegan como fichas entre corchetes, por ejemplo [CLIENTE_1] o [CSG_1]. Copia la ficha sin corchetes en su parámetro: CLIENTE_1. No inventes fichas.
4. Periodo. La planta trabaja por jornadas: una jornada va de 07:00 a 06:59 del día siguiente y se nombra por el día en que empieza. Turno A: 07:00 a 18:59. Turno B: 19:00 a 06:59. «Hoy» es la jornada en curso. «Anoche» es el turno B que terminó esta mañana, o el que está en curso si ya empezó. Las fechas van en AAAA-MM-DD.
   - `actual`: lo que hay ahora (existencias, camiones en planta, cargas abiertas) o la pregunta no nombra periodo; FoliOS usa la jornada en curso.
   - `jornada`: una sola jornada; desde y hasta son la misma fecha.
   - `turno`: una jornada y un turno; desde y hasta son la misma fecha.
   - `rango`: desde y hasta, las dos jornadas incluidas. «Esta semana» parte el lunes; «este mes», el día 1.
   - `temporada`: toda la temporada en curso. `temporada_anterior`: toda la temporada anterior.
   Si el tipo no usa fechas, deja desde y hasta vacías.
5. Usa un filtro solo si la pregunta lo nombra. Valores neutros: "" en cliente y csg, "todas" en especie, "ninguno" en turno, "nada" en agrupar_por. agrupar_por = jornada solo si la pregunta pide el detalle por día.
6. Las preguntas que nombran un código (REC-, CAR-, PF-, GDE-, folio, guía o lote con número) las responde FoliOS antes de llegar a ti.
7. Comprueba antes de consultar: mismo dominio, evento, estado, unidad y todos los filtros pedidos. «Validado» no es «pendiente», «repaletizado» no es «devuelto», «existencia ahora» no es «producción de ayer» y «folios» no son «cajas». Los formatos de PT son fruta embalada, no cajas de cartón disponibles en Materiales. Si una consulta cercana deja fuera una métrica o un filtro, decláralo en `sin_cubrir`; si ninguna consulta es pertinente, usa `sin_consulta`. Nunca quites parte de la pregunta para hacerla encajar. Si el dominio es ambiguo, pide aclaración.

Glosario de la planta: Romana es la báscula de recepción de fruta; REC- es una recepción de Romana; destare es el pesaje del camión al salir; tara es el peso del camión y los envases; bins, totes, esponjas y pallets cosecheros son envases de cosecha; CSG es el código del productor ante el SAG; Validación MP revisa la materia prima; Validación PT revisa el producto terminado; un folio es un pallet o un saldo de producto terminado; un saldo es un pallet incompleto; REPA es un repaletizaje; PF- es un proceso de prefrío en un túnel; CAR- es una carga de despacho; GDE- es una guía de despacho de envases; OT es una orden de trabajo de materiales.
TXT;

    public function __construct(private readonly ServicioTemporadaActiva $temporadas, private readonly CoberturaConsultaHaiku $cobertura) {}

    /**
     * @param  list<ConsultaAsistente>  $consultas  las permitidas al usuario
     * @return array{system: list<array<string, mixed>>, tools: list<array<string, mixed>>}
     */
    public function definir(array $consultas): array
    {
        $claves = array_map(fn (ConsultaAsistente $c): string => $c->clave(), $consultas);
        $catalogo = implode("\n", array_map(fn (ConsultaAsistente $c): string => sprintf(
            '- %s: %s Filtros: %s. Límites: %s',
            $c->clave(), $c->descripcion(), $c->filtros() === [] ? 'ninguno (lo de ahora)' : implode(', ', $c->filtros()), $this->cobertura->limites($c),
        ), $consultas));
        $texto = fn (string $descripcion): array => ['type' => 'string', 'description' => $descripcion];
        $enum = fn (array $valores, string $descripcion): array => ['type' => 'string', 'enum' => array_values($valores), 'description' => $descripcion];

        // El orden importa: Haiku escribe primero qué pide la pregunta y, ya elegida la consulta, qué le falta.
        $consultar = [
            'pide' => $texto('La cifra o lista que pide la pregunta y cada condición que pone, en pocas palabras.'),
            'consulta' => $enum($claves, 'Clave de la consulta del catálogo.'),
            'sin_cubrir' => ['type' => 'array', 'items' => ['type' => 'string'],
                'description' => 'Condiciones de la pregunta que ni la consulta ni sus parámetros cubren, con sus palabras. Vacía solo si la consulta responde todo lo que se pide.'],
            'periodo_tipo' => $enum(['actual', 'jornada', 'turno', 'rango', 'temporada', 'temporada_anterior'], 'Tipo de periodo; ver las reglas del sistema.'),
            'desde' => $texto('Primera jornada, AAAA-MM-DD, o vacío.'),
            'hasta' => $texto('Última jornada, AAAA-MM-DD, o vacío.'),
            'turno' => $enum(['ninguno', 'A', 'B'], 'Turno, solo con periodo_tipo turno.'),
            'cliente' => $texto('Ficha CLIENTE_n de la pregunta, o vacío.'),
            'csg' => $texto('Ficha CSG_n de la pregunta, o vacío.'),
            'especie' => $enum(['todas', ...$this->especies()], 'Especie nombrada en la pregunta, o todas.'),
            'agrupar_por' => $enum(['nada', 'jornada'], 'jornada solo si se pide el detalle por día.'),
        ];
        $opcion = ['type' => 'object', 'properties' => ['consulta' => $enum($claves, 'Clave de una consulta posible.')], 'required' => ['consulta'], 'additionalProperties' => false];

        return [
            'system' => [['type' => 'text', 'text' => self::SISTEMA, 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => [
                $this->herramienta(self::CONSULTAR, "Ejecuta una consulta del catálogo de FoliOS. Catálogo permitido a este usuario:\n{$catalogo}", $consultar),
                $this->herramienta(self::ACLARAR, 'Pide al usuario que elija entre dos a cuatro consultas del catálogo cuando la pregunta no permite decidir.', [
                    'opciones' => ['type' => 'array', 'items' => $opcion, 'description' => 'De dos a cuatro consultas posibles, la más probable primero.'],
                ]),
                $this->herramienta(self::SIN_CONSULTA, 'Ninguna consulta del catálogo responde la pregunta.', [
                    'motivo' => $enum(['otro_tema', 'comparacion', 'pronostico', 'recomendacion', 'dato_no_disponible'], 'Por qué no hay consulta.'),
                ]),
            ],
        ];
    }

    /** Mensaje del usuario: el contexto de fecha va aquí, fuera del prefijo que se guarda en caché. */
    public function mensaje(string $texto): string
    {
        $periodo = app(PeriodoOperativo::class);
        $ahora = now()->setTimezone(config('app.operational_timezone'));

        return sprintf(
            "Ahora en la planta: %s, jornada del %s, turno %s.\nPregunta: «%s»",
            $ahora->format('Y-m-d H:i'), $periodo->jornada(), $periodo->turno(), $texto,
        );
    }

    /** @return list<string> */
    private function especies(): array
    {
        $temporada = $this->temporadas->buscar();
        if ($temporada === null) {
            return [];
        }

        return EspecieValidacion::query()->where('temporada_id', $temporada->id)->where('activo', true)->orderBy('nombre')
            ->pluck('nombre')->map(fn ($nombre): string => TextoNormalizado::de((string) $nombre))->unique()->values()->all();
    }

    /** @param  array<string, array<string, mixed>>  $propiedades */
    private function herramienta(string $nombre, string $descripcion, array $propiedades): array
    {
        return [
            'name' => $nombre,
            'description' => $descripcion,
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => $propiedades,
                'required' => array_keys($propiedades),
                'additionalProperties' => false,
            ],
        ];
    }
}
