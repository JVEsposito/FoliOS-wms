<?php

namespace App\Services\Asistente;

use App\Exceptions\OperacionNoAutorizada;
use App\Models\PreguntaAsistente;
use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;

/**
 * Responde las preguntas del asistente (fase A: sin IA) y deja registro de cada una.
 * Cada respuesta trae la línea «Entendí» con la consulta, el periodo y los filtros.
 * Si no entiende, propone solo consultas parecidas ya completadas con lo reconocido,
 * más la búsqueda operacional; si ninguna se parece, lo dice en vez de inventar.
 */
class ServicioAsistente
{
    public const MODO = 'sin_ia';

    public function __construct(
        private readonly InterpreteReglasAsistente $interprete,
        private readonly CatalogoConsultasAsistente $catalogo,
        private readonly LineaEntendido $entendido,
    ) {}

    /**
     * @return array{tipo: string, modo: string, resultado: ?array, consulta: ?string, entendido?: array,
     *     sugerencias: list<array{clave: string, titulo: string, descripcion: string, parametros: array}>, busqueda: string, mensaje?: string}
     */
    public function preguntar(User $usuario, string $texto): array
    {
        $interpretacion = $this->interprete->interpretar($usuario, $texto);
        $consulta = $interpretacion['consulta'];
        $parametros = $interpretacion['parametros'];

        if ($consulta !== null) {
            $resultado = $consulta->ejecutar($usuario, $parametros);
            $this->registrar($usuario, $texto, $consulta->clave(), $parametros, $resultado->estado, $interpretacion['confianza']);

            return [
                'tipo' => 'respuesta',
                'modo' => self::MODO,
                'consulta' => $consulta->clave(),
                'entendido' => $this->entendido->construir($consulta, $parametros),
                'resultado' => $resultado->toArray(),
                'sugerencias' => [],
                'busqueda' => '',
            ];
        }

        $this->registrar($usuario, $texto, null, $parametros, 'sugerencias', $interpretacion['confianza']);
        $mensaje = $interpretacion['alternativas'] === []
            ? 'No tengo una consulta para esa pregunta. Puedes buscarla en FoliOS o ver lo que sé responder.'
            : 'No estoy seguro de qué consulta quieres. ¿Es alguna de estas?';

        return [
            'tipo' => 'sugerencias',
            'mensaje' => $mensaje,
            'modo' => self::MODO,
            'consulta' => null,
            'resultado' => null,
            'sugerencias' => array_map(fn (ConsultaAsistente $alternativa): array => [
                'clave' => $alternativa->clave(),
                'titulo' => $alternativa->titulo(),
                'descripcion' => $alternativa->descripcion(),
                'parametros' => $parametros->toArray(),
            ], $interpretacion['alternativas']),
            'busqueda' => trim($texto),
        ];
    }

    /** @return array{tipo: string, modo: string, consulta: string, entendido: array, resultado: array} */
    public function ejecutar(User $usuario, string $clave, ParametrosConsulta $parametros): array
    {
        $consulta = $this->catalogo->buscar($clave);
        if ($consulta === null || ! $consulta->puedeEjecutar($usuario)) {
            throw new OperacionNoAutorizada('No tienes acceso a esa consulta del asistente.');
        }

        return [
            'tipo' => 'respuesta',
            'modo' => self::MODO,
            'consulta' => $clave,
            'entendido' => $this->entendido->construir($consulta, $parametros),
            'resultado' => $consulta->ejecutar($usuario, $parametros)->toArray(),
        ];
    }

    /** @return list<array{clave: string, titulo: string, descripcion: string, dominio: string}> */
    public function catalogo(User $usuario): array
    {
        return array_map(fn (ConsultaAsistente $consulta): array => [
            'clave' => $consulta->clave(),
            'titulo' => $consulta->titulo(),
            'descripcion' => $consulta->descripcion(),
            'dominio' => $consulta->dominio(),
        ], $this->catalogo->disponiblesPara($usuario));
    }

    private function registrar(User $usuario, string $texto, ?string $consulta, ParametrosConsulta $parametros, string $estado, float $confianza): void
    {
        PreguntaAsistente::query()->create([
            'user_id' => $usuario->id,
            'texto' => mb_substr($texto, 0, 500),
            'interprete' => 'reglas',
            'consulta' => $consulta,
            'parametros' => $parametros->toArray() ?: null,
            'estado' => $estado,
            'confianza' => $confianza,
            'created_at' => now(),
        ]);
    }
}
