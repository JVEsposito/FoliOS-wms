<?php

namespace Tests\Fixtures;

use App\Services\Envases\DetalleEnvasesPorCsg;
use App\Services\Envases\ResumenEnvasesPorCsg;
use Carbon\CarbonImmutable;

/** Datos sintéticos para comprobar una temporada completa sin consultar datos de planta. */
class EstadoCuentaEnvases
{
    public static function crear(int $guias = 200, int $cantidadCsg = 12): array
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
        $movimientosCsg = [];
        for ($i = 1; $i <= $guias; $i++) {
            $entrada = $i % 2 === 1;
            $indiceCsg = intdiv($i - 1, 2) % max(1, $cantidadCsg) + 1;
            $csg = ['codigo' => (string) (100000 + $indiceCsg), 'nombre' => 'Productor de ejemplo PROD-'.str_pad((string) $indiceCsg, 5, '0', STR_PAD_LEFT)];
            $documento = [
                'fecha' => CarbonImmutable::parse('2026-11-01 08:00:00')->addMinutes($i * 8)->format('Y-m-d H:i:s'),
                'documento' => 'EJ-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'movimiento' => $entrada ? 'Recepción con fruta' : 'Despacho',
                'productor' => 'CSG '.$csg['codigo'].' · '.$csg['nombre'],
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
                $movimientosCsg[] = [
                    'movimiento' => $entrada ? 'recepcion_fruta' : 'despacho_cliente',
                    'tipo_envase' => $j, 'nombre_envase' => $tipos[$j]['nombre'], 'orden' => $j,
                    'entradas' => $entrada ? $cantidad : 0, 'salidas' => $entrada ? 0 : $cantidad, 'csg' => $csg,
                ];
            }
            $estado['grupos_documentos'][] = $documento;
        }
        $estado['tipos'] = $guias ? $tipos : [];
        $estado['resumen_csg'] = ResumenEnvasesPorCsg::generar($movimientosCsg, []);

        return $estado;
    }

    /** El mismo estado con el detalle por CSG, como lo arma ServicioCuentaCorrienteEnvases. */
    public static function conDetalleCsg(int $guias = 200, int $cantidadCsg = 12): array
    {
        $estado = self::crear($guias, $cantidadCsg);
        $tipos = [];
        $documentos = array_map(function (array $grupo) use (&$tipos): array {
            preg_match('/^CSG (\d+) · (.*)$/u', $grupo['productor'], $csg);
            $porTipo = [];
            foreach ($grupo['lineas'] as $orden => $linea) {
                $clave = 't'.$orden;
                $tipos[$clave] = ['nombre' => $linea['tipo_envase'], 'orden' => $orden];
                $porTipo[$clave] = ['nombre' => $linea['tipo_envase'], 'orden' => $orden,
                    'entradas' => (int) $linea['entrada'], 'salidas' => (int) $linea['salida']];
            }
            $entrada = $grupo['movimiento'] === 'Recepción con fruta';

            return ['fecha' => $grupo['fecha'], 'movimiento' => $entrada ? 'Ingreso con fruta' : 'Despacho de envases',
                'titulo' => ($entrada ? 'Guía de ingreso N° ' : 'Guía de despacho N° ').$grupo['documento'],
                'chofer' => 'Chofer CONDUCTOR '.substr($grupo['documento'], -3).' (RUT 12.345.678-5)',
                'referencia' => 'Recepción REC-'.$grupo['documento'].' · Camión AB1234 · carro CD5678',
                'asignaciones' => [['csg' => ['codigo' => $csg[1], 'nombre' => $csg[2]], 'compartido' => null, 'tipos' => $porTipo]]];
        }, $estado['grupos_documentos']);
        $estado['detalle_csg'] = DetalleEnvasesPorCsg::armar($documentos, [], $tipos);

        return $estado;
    }
}
