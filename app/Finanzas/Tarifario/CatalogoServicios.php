<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Captura\FuenteHechos;

/**
 * Lo que se puede cobrar: cada fuente de hechos es un servicio y cada una de sus medidas, una
 * unidad de cobro. En las fuentes que duran (la estadía en cámara) la unidad se cobra por día.
 */
final class CatalogoServicios
{
    /** Un bulto es un pallet o un saldo: cobrar por ambos en un servicio cobraría dos veces. */
    private const EXCLUYENTES = [
        'bultos' => ['pallets', 'saldos'],
        'pallets' => ['bultos'],
        'saldos' => ['bultos'],
    ];

    /** Nombres de unidad que la medida no dice por sí sola. */
    private const UNIDADES = [
        'kilos_netos' => 'kilo neto',
        'operaciones' => 'repaletizaje',
        'folios_origen' => 'folio de origen',
        'folios_resultantes' => 'folio resultante',
        'repaletizaje.cajas' => 'caja resultante',
    ];

    public function __construct(private readonly CatalogoFuentes $fuentes) {}

    /** @return list<array{clave: string, nombre: string, por_dia: bool, unidades: list<array{clave: string, nombre: string}>}> */
    public function descripcion(): array
    {
        return array_values(array_map(fn (FuenteHechos $fuente): array => [
            'clave' => $fuente->clave(),
            'nombre' => $fuente->nombre(),
            'por_dia' => $fuente->intervalo(),
            'unidades' => array_map(
                fn (string $unidad): array => ['clave' => $unidad, 'nombre' => $this->nombreUnidad($fuente->clave(), $unidad)],
                array_keys($fuente->medidas()),
            ),
        ], $this->fuentes->todas()));
    }

    /** @return list<string> */
    public function claves(): array
    {
        return array_keys($this->fuentes->todas());
    }

    public function existe(string $servicio, string $unidad): bool
    {
        $fuente = $this->fuentes->todas()[$servicio] ?? null;

        return $fuente !== null && array_key_exists($unidad, $fuente->medidas());
    }

    public function nombreServicio(string $servicio): string
    {
        return ($this->fuentes->todas()[$servicio] ?? null)?->nombre() ?? $servicio;
    }

    /** «por caja», «por pallet y día»… */
    public function nombreUnidad(string $servicio, string $unidad): string
    {
        $fuente = $this->fuentes->todas()[$servicio] ?? null;
        $medida = $fuente?->medidas()[$unidad] ?? null;
        if ($fuente === null || $medida === null) {
            return $unidad;
        }

        $nombre = self::UNIDADES["{$servicio}.{$unidad}"] ?? self::UNIDADES[$unidad] ?? $medida['unidad'];

        return 'por '.$nombre.($fuente->intervalo() ? ' y día' : '');
    }

    /** Posición en el recorrido de la fruta, para ordenar como en Hechos valorizables. */
    public function orden(string $servicio, ?string $unidad = null): int
    {
        $posicion = array_search($servicio, $this->claves(), true);
        $fuente = $this->fuentes->todas()[$servicio] ?? null;
        $subposicion = $unidad === null || $fuente === null ? 0 : array_search($unidad, array_keys($fuente->medidas()), true);

        return (($posicion === false ? 99 : $posicion) * 100) + ($subposicion === false ? 99 : $subposicion);
    }

    /** @return list<string> */
    public function excluyentes(string $unidad): array
    {
        return self::EXCLUYENTES[$unidad] ?? [];
    }
}
