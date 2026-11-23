<?php

namespace App\Services\Asistente;

use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\Consultas\VistaConsulta;

/**
 * Una opción que el asistente ofrece: un tema y, si tiene varias, una de sus vistas.
 * Sin vista elegida rige la del tema por defecto (o ninguna, si el tema no la tiene).
 */
final readonly class OpcionConsulta
{
    public ?VistaConsulta $vista;

    public function __construct(public ConsultaAsistente $consulta, ?VistaConsulta $vista = null)
    {
        $this->vista = $vista ?? $consulta->vista(null);
    }

    public static function de(ConsultaAsistente|self $opcion): self
    {
        return $opcion instanceof self ? $opcion : new self($opcion);
    }

    /** «Tema · Vista» si el tema tiene más de una vista; si no, el título del tema. */
    public function titulo(): string
    {
        return $this->vista !== null && count($this->consulta->vistas()) > 1
            ? "{$this->consulta->titulo()} · {$this->vista->titulo}"
            : $this->consulta->titulo();
    }

    public function descripcion(): string
    {
        return $this->vista?->descripcion ?? $this->consulta->descripcion();
    }

    /** Para quitar repetidos: «clave/vista». */
    public function clave(): string
    {
        return $this->consulta->clave().'/'.($this->vista?->clave ?? '');
    }
}
