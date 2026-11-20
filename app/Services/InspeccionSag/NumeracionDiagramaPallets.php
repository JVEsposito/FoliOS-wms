<?php

namespace App\Services\InspeccionSag;

use Illuminate\Validation\ValidationException;

/**
 * Numeración continua del diagrama de presentación de pallets SAG/USDA.
 *
 * La caja 1 es la primera de la corrida inferior del pallet 1; cada corrida se numera de
 * izquierda a derecha y de abajo hacia arriba, y cada pallet parte en el número siguiente
 * a la última caja del anterior. Es la misma regla de resources/js/shared/sag-pallet-diagram.js.
 */
class NumeracionDiagramaPallets
{
    public const MAXIMO_PALLETS = 80;

    public const MAXIMO_BASE = 50;

    public const MAXIMO_CORRIDAS = 50;

    public const MAXIMO_MARCAS = 3000;

    /**
     * @param  list<array{folio?: ?string, base: int, corridas: int, ultima?: ?int, declaradas?: ?int}>  $pallets
     * @return array{rangos: list<array{numero: int, folio: ?string, base: int, corridas: int, ultima: ?int, total: int, inicio: int, fin: int}>, total: int}
     *
     * @throws ValidationException si una última corrida o las cajas declaradas no cuadran.
     */
    public function numerar(array $pallets): array
    {
        $errores = [];
        $rangos = [];
        $siguiente = 1;
        foreach (array_values($pallets) as $indice => $pallet) {
            $base = (int) $pallet['base'];
            $corridas = (int) $pallet['corridas'];
            $ultima = isset($pallet['ultima']) ? (int) $pallet['ultima'] : null;
            if ($ultima !== null && $ultima >= $base) {
                $errores["pallets.{$indice}.ultima"] = 'Pallet '.($indice + 1).': la última corrida incompleta debe tener menos cajas que la base.';

                continue;
            }
            $total = $ultima === null ? $base * $corridas : $base * ($corridas - 1) + $ultima;
            $declaradas = isset($pallet['declaradas']) ? (int) $pallet['declaradas'] : null;
            if ($declaradas !== null && $declaradas !== $total) {
                $errores["pallets.{$indice}.declaradas"] = 'Pallet '.($indice + 1).": las cajas declaradas ({$declaradas}) no coinciden con la configuración ({$total}).";
            }
            $rangos[] = [
                'numero' => $indice + 1,
                'folio' => filled($pallet['folio'] ?? null) ? trim((string) $pallet['folio']) : null,
                'base' => $base,
                'corridas' => $corridas,
                'ultima' => $ultima,
                'total' => $total,
                'inicio' => $siguiente,
                'fin' => $siguiente + $total - 1,
            ];
            $siguiente += $total;
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
        $numeracion = ['rangos' => $rangos, 'total' => $siguiente - 1];
        $this->verificarContinuidad($numeracion);

        return $numeracion;
    }

    /**
     * Las marcas son números de caja: se ordenan, se quitan repetidos y no pueden
     * apuntar a una caja que no existe.
     *
     * @param  list<int>  $marcas
     * @return list<int>
     */
    public function marcas(array $marcas, array $numeracion): array
    {
        $marcas = array_values(array_unique(array_map('intval', $marcas)));
        sort($marcas);
        $inexistentes = array_values(array_filter($marcas, fn (int $numero): bool => $numero < 1 || $numero > $numeracion['total']));
        if ($inexistentes !== []) {
            throw ValidationException::withMessages(['cajas_marcadas' => 'Hay cajas marcadas que no existen en el diagrama ('
                .implode(', ', array_slice($inexistentes, 0, 10)).(count($inexistentes) > 10 ? '…' : '')
                .'). La numeración va de 1 a '.$numeracion['total'].'.']);
        }

        return $marcas;
    }

    /** @return array{pallet: int, corrida: int, posicion: int}|null Corrida 1 = inferior; posición 1 = izquierda. */
    public function ubicar(int $numero, array $numeracion): ?array
    {
        foreach ($numeracion['rangos'] as $indice => $rango) {
            if ($numero >= $rango['inicio'] && $numero <= $rango['fin']) {
                $desplazamiento = $numero - $rango['inicio'];

                return ['pallet' => $indice, 'corrida' => intdiv($desplazamiento, $rango['base']) + 1,
                    'posicion' => $desplazamiento % $rango['base'] + 1];
            }
        }

        return null;
    }

    /**
     * Corridas de un pallet de arriba hacia abajo, como se dibujan. La última corrida
     * incompleta queda arriba, con sus cajas desde la izquierda.
     *
     * @return list<list<int>>
     */
    public function corridas(array $rango): array
    {
        $corridas = [];
        for ($inicio = $rango['inicio']; $inicio <= $rango['fin']; $inicio += $rango['base']) {
            $corridas[] = range($inicio, min($rango['fin'], $inicio + $rango['base'] - 1));
        }

        return array_reverse($corridas);
    }

    /** Sin saltos ni repetidos: cada pallet parte donde terminó el anterior y el total es la suma. */
    private function verificarContinuidad(array $numeracion): void
    {
        $esperado = 1;
        foreach ($numeracion['rangos'] as $rango) {
            if ($rango['inicio'] !== $esperado || $rango['total'] !== $rango['fin'] - $rango['inicio'] + 1) {
                throw new \LogicException('La numeración del diagrama quedó con saltos.');
            }
            $esperado = $rango['fin'] + 1;
        }
        if ($esperado - 1 !== $numeracion['total'] || array_sum(array_column($numeracion['rangos'], 'total')) !== $numeracion['total']) {
            throw new \LogicException('El total del diagrama no coincide con la suma de los pallets.');
        }
    }
}
