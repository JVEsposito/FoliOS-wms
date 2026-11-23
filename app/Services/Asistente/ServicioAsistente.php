<?php

namespace App\Services\Asistente;

use App\Exceptions\OperacionNoAutorizada;
use App\Models\ConfiguracionAsistente;
use App\Models\PreguntaAsistente;
use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\Consultas\VistaConsulta;
use App\Services\Asistente\Haiku\ClienteAnthropic;
use App\Services\Asistente\Haiku\FallaHaiku;
use App\Services\Asistente\Haiku\InterpreteHaiku;
use App\Services\Asistente\Haiku\PresupuestoHaiku;
use App\Services\Asistente\Haiku\RecomendadorHaiku;
use DomainException;

/**
 * Responde las preguntas del asistente y deja registro de cada una. Las reglas responden
 * lo que entienden con certeza; en modo mixto, el resto va a Haiku como intérprete.
 * Cada respuesta trae la línea «Entendí» con la consulta, el periodo y los filtros.
 * Si no entiende, propone solo consultas parecidas ya completadas con lo reconocido,
 * más la búsqueda operacional; si ninguna se parece, lo dice en vez de inventar.
 */
class ServicioAsistente
{
    public const MODO = 'sin_ia';

    public const MODO_HAIKU = 'haiku';

    private const SIN_CONSULTA = 'No tengo una consulta para esa pregunta. Puedes buscarla en FoliOS o ver lo que sé responder.';

    public function __construct(
        private readonly InterpreteReglasAsistente $interprete,
        private readonly CatalogoConsultasAsistente $catalogo,
        private readonly LineaEntendido $entendido,
        private readonly InterpreteHaiku $haiku,
        private readonly RecomendadorHaiku $recomendador,
        private readonly ClienteAnthropic $anthropic,
    ) {}

    /**
     * @return array{tipo: string, modo: string, resultado: ?array, consulta: ?string, entendido?: array, aviso?: string,
     *     sugerencias: list<array{clave: string, titulo: string, descripcion: string, parametros: array}>, busqueda: string, mensaje?: string}
     */
    public function preguntar(User $usuario, string $texto): array
    {
        $interpretacion = $this->interprete->interpretar($usuario, $texto);
        $parametros = $interpretacion['parametros'];
        if ($interpretacion['consulta'] !== null) {
            $respuesta = $this->responder($usuario, $texto, $interpretacion['consulta'], $parametros, self::MODO, $interpretacion['confianza']);
            // Una recomendación nunca llega sola: se pide con el botón bajo la respuesta.
            if ($respuesta['recomendable'] && preg_match('/\b(recomienda[sn]?|recomendacion(es)?|sugieres|que hago|que harias)\b/', TextoNormalizado::de($texto))) {
                $respuesta['aviso'] = 'Para una recomendación, usa «Pedir recomendación» bajo esta respuesta.';
            }

            return $respuesta;
        }

        $mensaje = $interpretacion['motivo'] ?? null;
        $mensaje ??= $interpretacion['alternativas'] === [] ? self::SIN_CONSULTA : 'No estoy seguro de qué consulta quieres. ¿Es alguna de estas?';
        $aviso = null;

        // Modo mixto: lo que las reglas no entienden con certeza va a Haiku.
        if (ConfiguracionAsistente::actual()->modo === ConfiguracionAsistente::MODO_MIXTO) {
            $haiku = $this->haiku->interpretar($usuario, $texto, $interpretacion['entidades']);
            switch ($haiku['tipo']) {
                case InterpreteHaiku::CONSULTA:
                    return $this->responder($usuario, $texto, $haiku['consulta'], $haiku['parametros'], self::MODO_HAIKU, 1.0);
                case InterpreteHaiku::ACLARACION:
                    if ($haiku['opciones'] !== []) {
                        return $this->sugerir($usuario, $texto, $parametros, $haiku['opciones'], '¿Cuál de estas consultas quieres?', self::MODO_HAIKU);
                    }
                    break;
                case InterpreteHaiku::SIN_CONSULTA:
                    return $this->sugerir($usuario, $texto, $parametros, $interpretacion['alternativas'], match ($haiku['motivo']) {
                        'comparacion' => 'Todavía no comparo periodos. Puedo mostrar cada periodo por separado:',
                        'pronostico' => 'FoliOS no hace pronósticos: solo muestra lo registrado en la planta.',
                        'recomendacion' => 'Por ahora no doy recomendaciones. Puedo mostrarte los datos:',
                        default => self::SIN_CONSULTA,
                    }, self::MODO_HAIKU);
                case InterpreteHaiku::PARCIAL:
                    $opciones = array_slice($this->unicas([...$haiku['opciones'], ...$interpretacion['alternativas']]), 0, 3);

                    return $this->sugerir($usuario, $texto, $parametros, $opciones, sprintf(
                        'Lo más cercano que tengo es «%s», pero no cubre %s. ¿Te sirve igual o es alguna de estas?',
                        $haiku['consulta']->titulo(), $this->enumerar($haiku['sin_cubrir']),
                    ), self::MODO_HAIKU);
                case InterpreteHaiku::INVALIDA:
                    $opciones = array_slice($this->unicas([...$haiku['opciones'], ...$interpretacion['alternativas']]), 0, 3);

                    return $this->sugerir($usuario, $texto, $parametros, $opciones,
                        "Entendí la pregunta, pero no pude comprobar sus datos: {$haiku['motivo']}. ¿Es alguna de estas?", self::MODO_HAIKU);
                case InterpreteHaiku::SENSIBLE:
                    $aviso = 'Esta pregunta no salió de la planta: la respondo solo con las reglas de FoliOS.';
                    break;
                default:
                    $aviso = match ($haiku['motivo']) {
                        PresupuestoHaiku::TOPE_MENSUAL => 'Haiku llegó al tope de gasto del mes: respondo sin IA hasta el mes siguiente.',
                        PresupuestoHaiku::LIMITE_DIARIO => 'Llegaste a tu límite diario de preguntas con Haiku: respondo sin IA hasta la próxima jornada.',
                        FallaHaiku::SIN_RED => 'Haiku no está disponible porque no hay conexión: respondo sin IA.',
                        PresupuestoHaiku::SIN_CLAVE, PresupuestoHaiku::FUERA_DEL_PILOTO => null,
                        default => 'Haiku no respondió bien: respondo sin IA.',
                    };
            }
        }

        return $this->sugerir($usuario, $texto, $parametros, $interpretacion['alternativas'], $mensaje, self::MODO, $aviso);
    }

    /** @return array{tipo: string, modo: string, consulta: string, parametros: array, entendido: array, resultado: array, recomendable: bool} */
    public function ejecutar(User $usuario, string $clave, ParametrosConsulta $parametros): array
    {
        [$consulta, $vista] = $this->consultaYVista($usuario, $clave, $parametros);
        $parametros = $parametros->conVista($vista->clave);
        $resultado = $consulta->ejecutar($usuario, $parametros);

        return [
            'tipo' => 'respuesta',
            'modo' => self::MODO,
            'consulta' => $clave,
            'parametros' => $parametros->toArray(),
            'entendido' => $this->entendido->construir($consulta, $parametros),
            'resultado' => $resultado->toArray(),
            'recomendable' => $this->recomendable($vista, $resultado),
        ];
    }

    /**
     * La consulta y la vista pedidas, si el usuario puede verlas. Sin vista rige la por
     * defecto; una vista que el tema no tiene es una regla de negocio, nunca la por defecto.
     *
     * @return array{0: ConsultaAsistente, 1: VistaConsulta}
     */
    private function consultaYVista(User $usuario, string $clave, ParametrosConsulta $parametros): array
    {
        $consulta = $this->catalogo->buscar($clave);
        if ($consulta === null || ! $consulta->puedeEjecutar($usuario)) {
            throw new OperacionNoAutorizada('No tienes acceso a esa consulta del asistente.');
        }
        $vista = $consulta->vista($parametros->vista);
        if ($vista === null) {
            throw new DomainException('Esa consulta no tiene la vista pedida.');
        }
        if (! $consulta->puedeVer($usuario, $vista)) {
            throw new OperacionNoAutorizada('No tienes acceso a esa vista del asistente.');
        }

        return [$consulta, $vista];
    }

    /** @return list<array{clave: string, titulo: string, descripcion: string, dominio: string, vistas: list<array{clave: string, titulo: string, descripcion: string}>}> */
    public function catalogo(User $usuario): array
    {
        return array_map(fn (ConsultaAsistente $consulta): array => [
            'clave' => $consulta->clave(),
            'titulo' => $consulta->titulo(),
            'descripcion' => $consulta->descripcion(),
            'dominio' => $consulta->dominio(),
            'vistas' => array_values(array_map(
                fn (VistaConsulta $vista): array => ['clave' => $vista->clave, 'titulo' => $vista->titulo, 'descripcion' => $vista->descripcion],
                array_filter($consulta->vistas(), fn (VistaConsulta $vista): bool => $consulta->puedeVer($usuario, $vista)),
            )),
        ], $this->catalogo->disponiblesPara($usuario));
    }

    private function responder(User $usuario, string $texto, ConsultaAsistente $consulta, ParametrosConsulta $parametros, string $modo, float $confianza): array
    {
        [$consulta, $vista] = $this->consultaYVista($usuario, $consulta->clave(), $parametros);
        $parametros = $parametros->conVista($vista->clave);
        $resultado = $consulta->ejecutar($usuario, $parametros);
        $this->registrar($usuario, $texto, $consulta->clave(), $parametros, $resultado->estado, $confianza, $modo === self::MODO_HAIKU ? 'haiku' : 'reglas');

        return [
            'tipo' => 'respuesta',
            'modo' => $modo,
            'consulta' => $consulta->clave(),
            'parametros' => $parametros->toArray(),
            'entendido' => $this->entendido->construir($consulta, $parametros),
            'resultado' => $resultado->toArray(),
            'recomendable' => $this->recomendable($vista, $resultado),
            'sugerencias' => [],
            'busqueda' => '',
        ];
    }

    /** Se ofrece «Pedir recomendación» solo en modo mixto, con clave y sobre una respuesta con datos. */
    private function recomendable(VistaConsulta $vista, ResultadoConsulta $resultado): bool
    {
        return $vista->admiteRecomendacion
            && $resultado->estado === ResultadoConsulta::RESPONDIDA
            && $this->anthropic->disponible()
            && ConfiguracionAsistente::actual()->modo === ConfiguracionAsistente::MODO_MIXTO;
    }

    /**
     * Recomendación a pedido sobre una consulta que el usuario ya vio. La consulta se
     * vuelve a ejecutar con sus permisos; a Haiku solo va ese resultado.
     *
     * @return array{estado: string, aviso: string, texto: ?string, basada_en: list<string>, motivo: ?string}
     */
    public function recomendar(User $usuario, string $clave, ParametrosConsulta $parametros): array
    {
        [$consulta, $vista] = $this->consultaYVista($usuario, $clave, $parametros);
        if (ConfiguracionAsistente::actual()->modo !== ConfiguracionAsistente::MODO_MIXTO) {
            throw new DomainException('Las recomendaciones solo están disponibles con el asistente en modo mixto.');
        }

        return $this->recomendador->recomendar($usuario, $consulta, $consulta->ejecutar($usuario, $parametros->conVista($vista->clave)));
    }

    /**
     * «A», «A ni B» o «A, B ni C»: va después de «no cubre».
     *
     * @param  list<string>  $partes
     */
    private function enumerar(array $partes): string
    {
        $ultima = array_pop($partes);

        return $partes === [] ? (string) $ultima : implode(', ', $partes).' ni '.$ultima;
    }

    /**
     * Opciones para elegir: temas o pares tema y vista. Cada botón lleva su vista en los
     * parámetros, así que ejecuta exactamente lo que ofrece.
     *
     * @param  list<ConsultaAsistente|OpcionConsulta>  $opciones
     */
    private function sugerir(User $usuario, string $texto, ParametrosConsulta $parametros, array $opciones, string $mensaje, string $modo, ?string $aviso = null): array
    {
        $opciones = $this->unicas($opciones);
        $this->registrar($usuario, $texto, null, $parametros, 'sugerencias', 0.0, $modo === self::MODO_HAIKU ? 'haiku' : 'reglas');

        return array_filter([
            'tipo' => 'sugerencias',
            'mensaje' => $mensaje,
            'modo' => $modo,
            'aviso' => $aviso,
            'consulta' => null,
            'resultado' => null,
            'sugerencias' => array_map(fn (OpcionConsulta $opcion): array => [
                'clave' => $opcion->consulta->clave(),
                'vista' => $opcion->vista?->clave,
                'titulo' => $opcion->titulo(),
                'descripcion' => $opcion->descripcion(),
                'parametros' => $parametros->conVista($opcion->vista?->clave)->toArray(),
            ], $opciones),
            'busqueda' => trim($texto),
        ], fn ($valor, string $clave): bool => $clave !== 'aviso' || $valor !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  list<ConsultaAsistente|OpcionConsulta>  $opciones
     * @return list<OpcionConsulta>
     */
    private function unicas(array $opciones): array
    {
        return collect($opciones)->map(fn (ConsultaAsistente|OpcionConsulta $o): OpcionConsulta => OpcionConsulta::de($o))
            ->unique(fn (OpcionConsulta $o): string => $o->clave())->values()->all();
    }

    private function registrar(User $usuario, string $texto, ?string $consulta, ParametrosConsulta $parametros, string $estado, float $confianza, string $interprete = 'reglas'): void
    {
        PreguntaAsistente::query()->create([
            'user_id' => $usuario->id,
            'texto' => mb_substr($texto, 0, 500),
            'interprete' => $interprete,
            'consulta' => $consulta,
            'parametros' => $parametros->toArray() ?: null,
            'estado' => $estado,
            'confianza' => $confianza,
            'created_at' => now(),
        ]);
    }
}
