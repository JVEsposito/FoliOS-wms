<?php

namespace Tests\Fixtures;

use Carbon\CarbonImmutable;

/** Datos sintéticos para comprobar una temporada completa sin consultar datos de planta. */
class EstadoCuentaEnvases
{
    public static function crear(int $guias = 200): array
    {
        $tipos = array_map(fn ($nombre) => [
            'nombre' => $nombre, 'saldo_inicial' => 0, 'entradas' => 0, 'salidas' => 0,
            'saldo_final' => 0, 'salidas_por_propiedad' => ['propia' => 0, 'arrendada' => 0, 'cliente' => 0],
        ], ['Bins plástico', 'Esponja de bins', 'Tote']);
        $estado = [
            'cliente' => ['codigo' => 'DEMO', 'nombre' => 'DATOS DE EJEMPLO - '.($guias * 3).' MOVIMIENTOS'],
            'temporada' => ['codigo' => 'TEMP-2627', 'nombre' => '2026-2027'],
            'desde' => '2026-11-01', 'hasta' => '2026-11-05', 'emitido_at' => '2026-11-06 08:00:00',
            'movimientos' => [], 'grupos_documentos' => [], 'lineas_pendientes_validacion' => 6,
        ];
        for ($i = 1; $i <= $guias; $i++) {
            $entrada = $i % 2 === 1;
            $documento = [
                'fecha' => CarbonImmutable::parse('2026-11-01 08:00:00')->addMinutes($i * 8)->format('Y-m-d H:i:s'),
                'documento' => 'EJ-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'movimiento' => $entrada ? 'Recepción con fruta' : 'Despacho',
                'productor' => 'CSG '.(100000 + $i).' · Productor de ejemplo PROD-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'lineas' => [],
            ];
            foreach ([12, 6, 288] as $j => $base) {
                $cantidad = $base + $i % 5;
                $propiedad = $j === 1 ? 'propia' : 'arrendada';
                $tipos[$j][$entrada ? 'entradas' : 'salidas'] += $cantidad;
                $tipos[$j]['saldo_final'] += $entrada ? $cantidad : -$cantidad;
                if (! $entrada) {
                    $tipos[$j]['salidas_por_propiedad'][$propiedad] += $cantidad;
                }
                $linea = [
                    ...array_diff_key($documento, ['lineas' => true]),
                    'tipo_envase' => $tipos[$j]['nombre'],
                    'propiedad' => $propiedad === 'propia' ? 'Propia' : 'Arrendada',
                    'entrada' => $entrada ? $cantidad : null,
                    'salida' => $entrada ? null : $cantidad,
                    'saldo' => $tipos[$j]['saldo_final'],
                ];
                $documento['lineas'][] = $linea;
                $estado['movimientos'][] = $linea;
            }
            $estado['grupos_documentos'][] = $documento;
        }
        $estado['tipos'] = $guias ? $tipos : [];

        return $estado;
    }
}
