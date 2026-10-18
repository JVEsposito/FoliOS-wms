<?php

namespace App\Finanzas\Captura;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Fuente en que cada fila de una tabla operativa es un hecho (una recepción, una
 * validación...). Lee por lotes ordenados por identificador para no cargar todo en memoria.
 */
abstract class FuenteTabla implements FuenteHechos
{
    protected const LOTE = 500;

    public function intervalo(): bool
    {
        return false;
    }

    /** Consulta base, con el identificador del registro seleccionado como «id». */
    abstract protected function consulta(ContextoCaptura $contexto): Builder;

    /** Columna calificada del identificador (por ejemplo, «r.id»). */
    abstract protected function columnaId(): string;

    /**
     * Columnas cuya fecha indica que el registro cambió.
     *
     * @return list<string>
     */
    abstract protected function columnasCambio(): array;

    /**
     * @param  Collection<int, object>  $filas
     * @return iterable<HechoCapturado>
     */
    abstract protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable;

    public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable
    {
        $consulta = $this->consulta($contexto);
        if ($desde !== null) {
            $marca = $desde->utc()->format('Y-m-d H:i:s');
            $consulta->where(function (Builder $cambio) use ($marca): void {
                foreach ($this->columnasCambio() as $columna) {
                    $cambio->orWhere($columna, '>=', $marca);
                }
            });
        }

        yield from $this->porLotes($contexto, $consulta);
    }

    public function porIds(ContextoCaptura $contexto, array $origenIds): iterable
    {
        foreach (array_chunk(array_values(array_unique($origenIds)), static::LOTE) as $ids) {
            $filas = $this->consulta($contexto)->whereIn($this->columnaId(), $ids)->get();
            yield from $this->mapear($contexto, $filas);
        }
    }

    /** @return iterable<HechoCapturado> */
    private function porLotes(ContextoCaptura $contexto, Builder $consulta): iterable
    {
        $ultimo = null;
        do {
            $filas = (clone $consulta)->forPageAfterId(static::LOTE, $ultimo, $this->columnaId())->get();
            if ($filas->isEmpty()) {
                return;
            }

            yield from $this->mapear($contexto, $filas);
            $ultimo = $filas->last()->id;
        } while ($filas->count() === static::LOTE);
    }
}
