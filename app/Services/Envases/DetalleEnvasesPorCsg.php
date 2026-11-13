<?php

namespace App\Services\Envases;

/**
 * Detalle del estado de cuenta agrupado por productor (CSG): dentro de cada CSG, los
 * documentos en orden cronológico con el saldo acumulado de ese CSG por envase. El total de
 * cada grupo coincide con su fila del resumen por productor.
 */
class DetalleEnvasesPorCsg
{
    /**
     * @param  list<array{fecha: ?string, titulo: string, chofer: ?string, referencia: ?string, movimiento: string, asignaciones: list<array{csg: ?array, compartido: ?string, tipos: array<string, array{nombre: string, orden: int, entradas: int, salidas: int}>}>}>  $documentos  en orden cronológico
     * @param  array<string, array<string, int>>  $saldosIniciales  por clave de CSG y tipo de envase
     * @param  array<string, array{nombre: string, orden: int}>  $tipos
     * @return list<array<string, mixed>>
     */
    public static function armar(array $documentos, array $saldosIniciales, array $tipos): array
    {
        $grupos = [];
        $saldos = $saldosIniciales;
        foreach ($saldosIniciales as $clave => $porTipo) {
            if (array_filter($porTipo)) {
                $grupos[$clave] ??= self::grupo($clave, null);
            }
        }
        foreach ($documentos as $documento) {
            foreach ($documento['asignaciones'] as $asignacion) {
                $clave = ResumenEnvasesPorCsg::clave($asignacion['csg']);
                $grupos[$clave] ??= self::grupo($clave, $asignacion['csg']);
                if (! $grupos[$clave]['nombre'] && ! empty($asignacion['csg']['nombre'])) {
                    $grupos[$clave]['nombre'] = $asignacion['csg']['nombre'];
                }
                $filas = $asignacion['tipos'];
                uasort($filas, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
                $lineas = [];
                foreach ($filas as $tipo => $fila) {
                    if ($fila['entradas'] === 0 && $fila['salidas'] === 0) {
                        continue;
                    }
                    $saldos[$clave][$tipo] = ($saldos[$clave][$tipo] ?? 0) + $fila['entradas'] - $fila['salidas'];
                    $grupos[$clave]['movidos'][$tipo] ??= ['entradas' => 0, 'salidas' => 0];
                    $grupos[$clave]['movidos'][$tipo]['entradas'] += $fila['entradas'];
                    $grupos[$clave]['movidos'][$tipo]['salidas'] += $fila['salidas'];
                    $lineas[] = ['tipo_envase' => $fila['nombre'], 'entrada' => $fila['entradas'] ?: null,
                        'salida' => $fila['salidas'] ?: null, 'saldo' => $saldos[$clave][$tipo]];
                }
                if ($lineas) {
                    $grupos[$clave]['documentos'][] = [
                        'fecha' => $documento['fecha'], 'titulo' => $documento['titulo'], 'chofer' => $documento['chofer'],
                        'movimiento' => $documento['movimiento'], 'referencia' => $documento['referencia'],
                        'compartido' => $asignacion['compartido'], 'lineas' => $lineas,
                    ];
                }
            }
        }
        uksort($grupos, function (string $a, string $b): int {
            if ($a === 'sin_csg' || $b === 'sin_csg') {
                return ($a === 'sin_csg') <=> ($b === 'sin_csg');
            }

            return strnatcmp($a, $b);
        });

        return array_values(array_map(function (array $grupo) use ($saldosIniciales, $tipos): array {
            $clave = $grupo['clave'];
            $claves = array_unique([...array_keys($saldosIniciales[$clave] ?? []), ...array_keys($grupo['movidos'])]);
            usort($claves, fn (string $a, string $b): int => ($tipos[$a]['orden'] ?? 99) <=> ($tipos[$b]['orden'] ?? 99));
            $totales = [];
            foreach ($claves as $tipo) {
                $inicial = $saldosIniciales[$clave][$tipo] ?? 0;
                $movido = $grupo['movidos'][$tipo] ?? ['entradas' => 0, 'salidas' => 0];
                if ($inicial === 0 && $movido['entradas'] === 0 && $movido['salidas'] === 0) {
                    continue;
                }
                $totales[] = ['tipo_envase' => $tipos[$tipo]['nombre'] ?? $tipo, 'saldo_inicial' => $inicial,
                    'entradas' => $movido['entradas'], 'salidas' => $movido['salidas'],
                    'saldo_final' => $inicial + $movido['entradas'] - $movido['salidas']];
            }
            unset($grupo['clave'], $grupo['movidos']);

            return [...$grupo, 'totales' => $totales];
        }, $grupos));
    }

    private static function grupo(string $clave, ?array $csg): array
    {
        return ['clave' => $clave, 'codigo' => $clave === 'sin_csg' ? null : substr($clave, 4),
            'nombre' => $csg['nombre'] ?? '', 'documentos' => [], 'movidos' => []];
    }
}
