<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use DomainException;

/**
 * Un tema del asistente con varias vistas: «Cuenta de envases» con saldos, por
 * productor, estado de cuenta… Las reglas y Haiku eligen el tema y la vista; el tema
 * ejecuta la vista con el mismo cálculo que su oficina.
 */
abstract class ConsultaPorTema extends ConsultaBase
{
    /** @return list<VistaConsulta> */
    abstract protected function vistasDelTema(): array;

    /** @return list<string> palabras del tema, ya normalizadas, que valen para todas sus vistas */
    abstract protected function palabrasDelTema(): array;

    abstract protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta;

    public function vistas(): array
    {
        return $this->vistasDelTema();
    }

    /** Un tema declara su vista por defecto solo si es la lectura obvia; si no, se pregunta. */
    public function vistaPorDefecto(): ?string
    {
        return null;
    }

    /** Las vistas eligen después del tema: aquí cuentan sus palabras sueltas, no sus frases. */
    public function palabrasClave(): array
    {
        $vistas = array_merge(...array_map(fn (VistaConsulta $v): array => $v->palabras, $this->vistas()));

        return array_values(array_unique([
            ...$this->palabrasDelTema(),
            ...array_filter($vistas, fn (string $palabra): bool => ! str_contains($palabra, ' ')),
        ]));
    }

    public function filtros(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (VistaConsulta $v): array => $v->filtros, $this->vistas()))));
    }

    public function admiteRecomendacion(): bool
    {
        foreach ($this->vistas() as $vista) {
            if ($vista->admiteRecomendacion) {
                return true;
            }
        }

        return false;
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $vista = $this->vista($parametros->vista);
        if ($vista === null) {
            throw new DomainException("«{$this->titulo()}» no tiene esa vista.");
        }

        return $this->ejecutarVista($vista, $usuario, $parametros->conVista($vista->clave));
    }
}
