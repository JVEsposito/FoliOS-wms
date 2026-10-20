<?php

namespace App\Gestion;

/**
 * Módulos de gestión con base propia. Cada módulo se registra desde su proveedor; la base común
 * no conoce a ninguno por su nombre.
 */
final class ModulosGestion
{
    /** @var array<class-string<EsquemaModulo>, true> */
    private array $esquemas = [];

    /** @param  class-string<EsquemaModulo>  $esquema */
    public function registrar(string $esquema): void
    {
        $this->esquemas[$esquema] = true;
    }

    /** @return list<EsquemaModulo> */
    public function esquemas(): array
    {
        return array_map(fn (string $esquema): EsquemaModulo => app($esquema), array_keys($this->esquemas));
    }
}
