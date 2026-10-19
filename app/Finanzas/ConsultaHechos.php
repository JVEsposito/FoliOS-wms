<?php

namespace App\Finanzas;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Models\Hecho;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/** Consultas sobre los hechos capturados (nunca sobre la operación). */
final class ConsultaHechos
{
    public function __construct(private readonly CatalogoFuentes $fuentes) {}

    /**
     * Última versión de los hechos del mes: los puntuales por su fecha operacional y los que
     * duran (estadías) si se cruzan con el mes, aunque hayan empezado antes.
     *
     * @return Builder<Hecho>
     */
    public function delPeriodo(Periodo $periodo): Builder
    {
        $consulta = Hecho::query()->vigentes();
        $this->cruzaPeriodo($consulta, $periodo);

        return $consulta;
    }

    /**
     * Agrega la condición «el hecho cae en el mes» a una consulta, sin filtrar versiones. Con
     * $tabla, las columnas llevan ese alias (para subconsultas sobre otra versión del hecho).
     */
    public function cruzaPeriodo(QueryBuilder $consulta, Periodo $periodo, string $tabla = ''): void
    {
        $intervalos = $this->fuentes->intervalos();
        $columna = fn (string $nombre): string => $tabla === '' ? $nombre : "{$tabla}.{$nombre}";

        $consulta->where(fn (QueryBuilder $alguno) => $alguno
            ->where(fn (QueryBuilder $puntuales) => $puntuales
                ->whereNotIn($columna('fuente'), $intervalos)
                ->whereBetween($columna('fecha_operacional'), [$periodo->inicio->toDateString(), $periodo->fin->toDateString()]))
            ->orWhere(fn (QueryBuilder $duraderos) => $duraderos
                ->whereIn($columna('fuente'), $intervalos)
                ->where($columna('ocurrido_at'), '<', $periodo->hastaUtc())
                ->where(fn (QueryBuilder $termino) => $termino->whereNull($columna('termina_at'))->orWhere($columna('termina_at'), '>=', $periodo->desdeUtc()))));
    }

    /**
     * Por fuente: cuántos hechos, cuántos anulados, cuántos sin cliente y la suma de cada medida
     * de los vigentes.
     *
     * @param  Builder<Hecho>  $consulta
     * @return list<array{fuente: string, nombre: string, hechos: int, anulados: int, sin_cliente: int, medidas: array<string, float>}>
     */
    public function resumen(Builder $consulta): array
    {
        $medidas = collect($this->fuentes->todas())
            ->flatMap(fn ($fuente): array => array_keys($fuente->medidas()))
            ->unique()
            ->filter(fn (string $clave): bool => preg_match('/^[a-z_]+$/', $clave) === 1)
            ->values();

        $sumas = $medidas->map(fn (string $clave): string => "SUM(CASE WHEN estado = 'vigente' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(medidas, '$.{$clave}')) AS DECIMAL(18,3)) ELSE 0 END) AS m_{$clave}")
            ->implode(', ');

        $filas = (clone $consulta)
            ->toBase()
            ->selectRaw("fuente, SUM(estado = 'vigente') AS hechos, SUM(estado = 'anulado') AS anulados, SUM(estado = 'vigente' AND cliente_id IS NULL) AS sin_cliente, {$sumas}")
            ->groupBy('fuente')
            ->get()
            ->keyBy('fuente');

        $resumen = [];
        foreach ($this->fuentes->todas() as $clave => $fuente) {
            $fila = $filas->get($clave);
            $resumen[] = [
                'fuente' => $clave,
                'nombre' => $fuente->nombre(),
                'hechos' => (int) ($fila->hechos ?? 0),
                'anulados' => (int) ($fila->anulados ?? 0),
                'sin_cliente' => (int) ($fila->sin_cliente ?? 0),
                'medidas' => collect($fuente->medidas())
                    ->mapWithKeys(fn (array $medida, string $medidaClave): array => [$medidaClave => round((float) ($fila->{"m_{$medidaClave}"} ?? 0), 3)])
                    ->all(),
            ];
        }

        return $resumen;
    }
}
