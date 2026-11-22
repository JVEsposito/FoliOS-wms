<?php

namespace App\Services\Asistente;

use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaAsistente;

/**
 * Catálogo de consultas del asistente. Las consultas se registran en el contenedor con
 * la etiqueta `asistente.consultas` (AsistenteServiceProvider).
 */
class CatalogoConsultasAsistente
{
    /** @var list<ConsultaAsistente> */
    private array $consultas;

    /** @param  iterable<ConsultaAsistente>  $consultas */
    public function __construct(iterable $consultas)
    {
        $this->consultas = array_values([...$consultas]);
    }

    /** @return list<ConsultaAsistente> */
    public function todas(): array
    {
        return $this->consultas;
    }

    /** @return list<ConsultaAsistente> */
    public function disponiblesPara(User $usuario): array
    {
        return array_values(array_filter(
            $this->consultas,
            fn (ConsultaAsistente $consulta): bool => $consulta->puedeEjecutar($usuario),
        ));
    }

    public function buscar(string $clave): ?ConsultaAsistente
    {
        foreach ($this->consultas as $consulta) {
            if ($consulta->clave() === $clave) {
                return $consulta;
            }
        }

        return null;
    }
}
