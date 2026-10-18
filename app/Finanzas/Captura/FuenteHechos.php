<?php

namespace App\Finanzas\Captura;

use Carbon\CarbonImmutable;

/**
 * Una fuente de hechos valorizables: un tipo de registro operativo que Finanzas sabe leer.
 * Solo consulta la operación a través del contexto (LectorOperacion); nunca escribe.
 */
interface FuenteHechos
{
    /** Clave estable que se guarda en fin_hechos.fuente. */
    public function clave(): string;

    public function nombre(): string;

    public function descripcion(): string;

    /** Sus hechos duran un tiempo (ocurrido_at → termina_at), como una estadía en cámara. */
    public function intervalo(): bool;

    /**
     * Medidas que trae cada hecho, con su unidad, en el orden en que se muestran.
     *
     * @return array<string, array{nombre: string, unidad: string}>
     */
    public function medidas(): array;

    /**
     * Estado actual de los registros que cambiaron desde $desde, o de todos si es null.
     * Un registro que dejó de ser un hecho (anulado, reabierto...) vuelve como anulado.
     *
     * @return iterable<HechoCapturado>
     */
    public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable;

    /**
     * Estado actual de estos registros. Los que ya no existen en la operación se omiten:
     * la conciliación diaria los anula.
     *
     * @param  list<string>  $origenIds
     * @return iterable<HechoCapturado>
     */
    public function porIds(ContextoCaptura $contexto, array $origenIds): iterable;
}
