<?php

namespace App\Finanzas\Captura;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Adaptador de lectura definido por el módulo, con medidas y dimensiones públicas. */
final class FuenteOperativa extends FuenteTabla
{
    public function __construct(private readonly array $definicion) {}

    public function clave(): string
    {
        return $this->definicion['clave'];
    }

    public function nombre(): string
    {
        return $this->definicion['nombre'];
    }

    public function descripcion(): string
    {
        return $this->definicion['descripcion'];
    }

    public function medidas(): array
    {
        return $this->definicion['medidas'];
    }

    public function contexto(): array
    {
        return ['area' => $this->definicion['area'], 'dimensiones' => $this->definicion['dimensiones']];
    }

    protected function columnaId(): string
    {
        return $this->definicion['id'];
    }

    protected function columnasCambio(): array
    {
        return $this->definicion['cambios'];
    }

    protected function consulta(ContextoCaptura $contexto): Builder
    {
        return ($this->definicion['consulta'])($contexto);
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        $extra = isset($this->definicion['enriquecer']) ? ($this->definicion['enriquecer'])($contexto, $filas) : [];
        foreach ($filas as $fila) {
            yield ($this->definicion['hecho'])($contexto, $fila, $extra);
        }
    }
}
