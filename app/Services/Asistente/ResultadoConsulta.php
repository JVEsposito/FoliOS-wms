<?php

namespace App\Services\Asistente;

/**
 * Respuesta de una consulta: un resumen en palabras, cifras clave y, cuando aplica,
 * filas con el detalle y el enlace a la oficina donde se trabaja el dato.
 */
final readonly class ResultadoConsulta
{
    public const RESPONDIDA = 'respondida';

    public const SIN_DATOS = 'sin_datos';

    public const MAXIMO_FILAS = 50;

    /**
     * @param  list<array{etiqueta: string, valor: int|float|string, unidad: ?string}>  $cifras
     * @param  list<array{clave: string, titulo: string, tipo?: string}>  $columnas
     * @param  list<array<string, mixed>>  $filas
     */
    public function __construct(
        public string $estado,
        public string $titulo,
        public string $resumen,
        public array $cifras = [],
        public array $columnas = [],
        public array $filas = [],
        public ?string $enlace = null,
    ) {}

    public static function sinDatos(string $titulo, string $resumen, ?string $enlace = null): self
    {
        return new self(self::SIN_DATOS, $titulo, $resumen, enlace: $enlace);
    }

    public static function cifra(string $etiqueta, int|float|string $valor, ?string $unidad = null): array
    {
        return ['etiqueta' => $etiqueta, 'valor' => $valor, 'unidad' => $unidad];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'estado' => $this->estado,
            'titulo' => $this->titulo,
            'resumen' => $this->resumen,
            'cifras' => $this->cifras,
            'columnas' => $this->columnas,
            'filas' => array_slice($this->filas, 0, self::MAXIMO_FILAS),
            'enlace' => $this->enlace,
        ];
    }
}
