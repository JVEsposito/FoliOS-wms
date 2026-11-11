<?php

namespace App\Services\Envases;

/** Atribución de los movimientos al CSG sin prorrateos. */
class ResumenEnvasesPorCsg
{
    public static function generar(array $movimientos, array $recepciones, array $anteriores = []): array
    {
        $resumen = self::atribuir($movimientos, $recepciones);
        foreach (self::atribuir($anteriores, $recepciones) as $clave => $grupo) {
            foreach ($grupo['tipos'] as $tipo => $fila) {
                $saldoInicial = $fila['entradas'] - $fila['salidas'];
                if ($saldoInicial === 0) {
                    continue;
                }
                $resumen[$clave] ??= [...$grupo, 'tipos' => []];
                $resumen[$clave]['tipos'][$tipo] ??= [...$fila, 'entradas' => 0, 'salidas' => 0];
                $resumen[$clave]['tipos'][$tipo]['saldo_inicial'] = $saldoInicial;
            }
        }
        uasort($resumen, function ($a, $b) {
            if ($a['codigo'] === null || $b['codigo'] === null) {
                return ($a['codigo'] === null) <=> ($b['codigo'] === null);
            }

            return strnatcmp($a['codigo'], $b['codigo']);
        });
        foreach ($resumen as &$grupo) {
            uasort($grupo['tipos'], fn ($a, $b) => $a['orden'] <=> $b['orden']);
            $grupo['tipos'] = array_values(array_map(fn (array $fila): array => [
                ...$fila, 'saldo_final' => ($fila['saldo_inicial'] ?? 0) + $fila['entradas'] - $fila['salidas'],
            ], $grupo['tipos']));
        }
        unset($grupo);

        return array_values($resumen);
    }

    private static function atribuir(array $movimientos, array $recepciones): array
    {
        $resumen = [];
        $entradasRecepcion = [];
        foreach ($movimientos as $linea) {
            $id = $linea['recepcion_id'] ?? null;
            if ($linea['movimiento'] === 'recepcion_fruta' && $id) {
                $entradasRecepcion[$id][] = $linea;

                continue;
            }
            $csg = $linea['csg'] ?? null;
            if (! $csg && $linea['movimiento'] === 'salida_recepcion_fruta' && $id) {
                $opciones = $recepciones[$id]['csg'] ?? [];
                // La devolución global de una recepción multiorigen no contiene reparto.
                $csg = count($opciones) === 1 ? $opciones[0] : null;
            }
            self::agregar($resumen, $csg, $linea);
        }
        foreach ($entradasRecepcion as $id => $lineas) {
            $datos = $recepciones[$id] ?? [];
            $opciones = $datos['csg'] ?? [];
            if (count($opciones) === 1) {
                foreach ($lineas as $linea) {
                    self::agregar($resumen, $opciones[0], $linea);
                }

                continue;
            }
            $porTipo = [];
            foreach ($lineas as $linea) {
                $tipo = $linea['tipo_envase'];
                $porTipo[$tipo] ??= [...$linea, 'entradas' => 0, 'salidas' => 0];
                $porTipo[$tipo]['entradas'] += $linea['entradas'];
                $porTipo[$tipo]['salidas'] += $linea['salidas'];
            }
            foreach ($porTipo as $tipo => $linea) {
                $reparto = $datos['envases'][$tipo] ?? [];
                $total = array_sum(array_column($reparto, 'cantidad'));
                $cuadra = $reparto && $linea['salidas'] === 0 && $linea['entradas'] >= 0
                    && $total === $linea['entradas']
                    && ! array_filter($reparto, fn ($parte) => $parte['cantidad'] < 0);
                if (! $cuadra) {
                    self::agregar($resumen, null, $linea);

                    continue;
                }
                foreach ($reparto as $parte) {
                    self::agregar($resumen, $parte, [...$linea, 'entradas' => $parte['cantidad'], 'salidas' => 0]);
                }
            }
        }

        return $resumen;
    }

    private static function agregar(array &$resumen, ?array $csg, array $linea): void
    {
        if ($linea['entradas'] === 0 && $linea['salidas'] === 0) {
            return;
        }
        $codigo = trim((string) ($csg['codigo'] ?? '')) ?: null;
        $clave = $codigo === null ? 'sin_csg' : 'csg:'.$codigo;
        $resumen[$clave] ??= ['codigo' => $codigo, 'nombre' => $csg['nombre'] ?? '', 'tipos' => []];
        if (! $resumen[$clave]['nombre'] && ! empty($csg['nombre'])) {
            $resumen[$clave]['nombre'] = $csg['nombre'];
        }
        $tipo = $linea['tipo_envase'];
        $resumen[$clave]['tipos'][$tipo] ??= [
            'tipo_envase' => $tipo, 'nombre' => $linea['nombre_envase'], 'orden' => $linea['orden'],
            'entradas' => 0, 'salidas' => 0,
        ];
        $resumen[$clave]['tipos'][$tipo]['entradas'] += $linea['entradas'];
        $resumen[$clave]['tipos'][$tipo]['salidas'] += $linea['salidas'];
    }
}
