<?php

namespace App\Services\Asistente\Consultas;

/**
 * Una vista de un tema del asistente: «Saldos por productor» dentro de «Cuenta de
 * envases». Las consultas que no son temas tienen una sola vista, `general`.
 */
final readonly class VistaConsulta
{
    public const GENERAL = 'general';

    /**
     * @param  list<string>  $palabras  ya normalizadas; las frases (con espacio) solo sirven para elegir la vista
     * @param  list<string>  $filtros  los que la vista aplica de verdad: periodo, cliente, csg, especie, codigo o por_dia
     * @param  string|null  $orden  criterio fijo de una vista ordenada, por ejemplo «antigüedad, de mayor a menor»
     */
    public function __construct(
        public string $clave,
        public string $titulo,
        public string $descripcion,
        public array $palabras = [],
        public array $filtros = [],
        public ?string $orden = null,
        public bool $admiteRecomendacion = false,
    ) {}
}
