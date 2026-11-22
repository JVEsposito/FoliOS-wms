<?php

namespace App\Services\Asistente;

use App\Exceptions\OperacionNoAutorizada;
use App\Models\PreguntaAsistente;
use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;

/**
 * Responde las preguntas del asistente (fase A: sin IA) y deja registro de cada una.
 * Si no entiende, propone consultas ya completadas con lo reconocido y la búsqueda
 * operacional; nunca termina en blanco.
 */
class ServicioAsistente
{
    public const MODO = 'sin_ia';

    public function __construct(
        private readonly InterpreteReglasAsistente $interprete,
        private readonly CatalogoConsultasAsistente $catalogo,
    ) {}

    /**
     * @return array{tipo: string, modo: string, resultado: ?array, consulta: ?string,
     *     sugerencias: list<array{clave: string, titulo: string, descripcion: string, parametros: array}>, busqueda: string}
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
                'resultado' => $resultado->toArray(),
                'sugerencias' => [],
                'busqueda' => '',
            ];
        }

        $this->registrar($usuario, $texto, null, $parametros, 'sugerencias', $interpretacion['confianza']);

        return [
            'tipo' => 'sugerencias',
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

    public function ejecutar(User $usuario, string $clave, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $consulta = $this->catalogo->buscar($clave);
        if ($consulta === null || ! $consulta->puedeEjecutar($usuario)) {
            throw new OperacionNoAutorizada('No tienes acceso a esa consulta del asistente.');
        }

        return $consulta->ejecutar($usuario, $parametros);
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
