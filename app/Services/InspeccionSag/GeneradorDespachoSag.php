<?php

namespace App\Services\InspeccionSag;

use App\Models\DocumentoSag;
use App\Services\Documentos\DocumentoPdfPlanta;

class GeneradorDespachoSag extends GeneradorDocumentoSag
{
    public function datos(?DocumentoSag $documento, string $tipo): array
    {
        $base = parent::datos($documento, 'despacho');
        $detalle = $documento?->detalle ?? [];
        $d = $detalle['transporte'] ?? [];
        $c = $base['campos'];
        $campos = [...$c, ...array_map(fn ($v) => is_bool($v) ? ($v ? 'SI' : 'NO') : ($v ?? ''), $d),
            'titulo' => ($d['modo'] ?? '') === 'contingencia' ? 'PLANILLA DE DESPACHO · CONTINGENCIA' : 'DATOS PARA MULTIPUERTO',
            'pallets' => $detalle['pallets'] ?? ''];
        foreach (['agente', 'rut_agente', 'exportador', 'rut_exportador', 'nave_vuelo', 'destino_pais', 'destino_ciudad', 'consignatario', 'puerto_embarque', 'oficina_sag_puerto', 'contenedor', 'sello', 'ubicacion_sello', 'patente', 'patente_trasera', 'guias', 'tipo_produccion', 'transgenico', 'tratamiento', 'detalle_tratamiento', 'nimf15', 'inspeccionado', 'motivo_contingencia', 'referencia_multipuerto'] as $campo) {
            $campos[$campo] ??= '';
        }
        $grupos = [];
        $anexo = [];
        foreach ($detalle['lineas'] ?? [] as $l) {
            $clave = json_encode([$l['especie'], $l['variedad'], $l['lote']]);
            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [$l['especie'], $l['variedad'], 'FRESCO', $l['lote'], 'FRUTA FRESCA: '.$l['especie'], $d['tipo_produccion'] ?? '', $d['transgenico'] ?? '', 0, 'CAJAS', 0, 'KILOGRAMOS'];
            }
            $grupos[$clave][7] += $l['cantidad'];
            $grupos[$clave][9] = $l['kilos'] === null || $grupos[$clave][9] === '' ? '' : (int) $grupos[$clave][9] + (int) round($l['kilos'] * 10000);
            $anexo[] = [$l['folio'], $l['especie'], $l['variedad'], $l['provincia'], $l['comuna'], $l['cantidad'], $l['csg'], $l['csp'], $l['kilos'] ?? '', $l['lote']];
        }
        $filas = array_values($grupos);
        foreach ($filas as &$fila) {
            $fila[9] = $fila[9] === '' ? '' : $fila[9] / 10000;
        }
        unset($fila);
        $columnas = ['Especie', 'Variedad', 'Condición', 'N° lote', 'Producto', 'Producción', 'Transgénico', 'Cantidad envases', 'Tipo envase', 'Cantidad producto', 'Unidad'];
        $columnasAnexo = ['Folio', 'Especie', 'Variedad', 'Provincia', 'Comuna', 'Cajas', 'CSG', 'CSP', 'Kilos', 'N° lote'];
        $campos['total_kilos'] = $documento && ! collect($detalle['lineas'] ?? [])->contains(fn ($l) => $l['kilos'] === null) ? collect($detalle['lineas'])->sum(fn ($l) => (int) round($l['kilos'] * 10000)) / 10000 : '';
        $seccion = ['campos' => $campos, 'columnas' => $columnas, 'filas' => $filas, 'capacidad' => 10, 'plantilla' => 1, 'inicio_filas' => 25, 'fin_impresion' => 58];
        $notas = [];
        foreach ([...$d, 'observaciones' => $c['observaciones'], 'nota' => $c['nota']] as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            foreach (app(DocumentoPdfPlanta::class)->envolver(is_bool($valor) ? ($valor ? 'SI' : 'NO') : (string) $valor, 700, 9) as $linea) {
                $notas[] = [str_replace('_', ' ', $campo), $linea];
            }
        }
        $secciones = [$seccion, ['campos' => $campos, 'columnas' => $columnasAnexo, 'filas' => $anexo, 'capacidad' => 20, 'plantilla' => 2]];
        if ($notas) {
            $secciones[] = ['campos' => $campos, 'columnas' => ['Antecedente', 'Detalle'], 'filas' => $notas, 'capacidad' => 20, 'plantilla' => 3];
        }

        return [...$base, 'campos' => $campos, 'filas' => $filas, 'columnas' => $columnas,
            'secciones' => $secciones, 'anexo' => $anexo, 'columnas_anexo' => $columnasAnexo];
    }

    public function pdf(?DocumentoSag $documento, string $tipo): string
    {
        $datos = $this->datos($documento, $tipo);
        $c = $datos['campos'];
        $pdf = app(DocumentoPdfPlanta::class);
        $paginas = [];
        $encabezado = [$c['titulo'], $c['codigo'].' · Versión de planta '.$c['version'].' · Vigencia '.$c['vigencia'],
            'N° interno '.$c['numero'].' · Fecha '.$c['fecha'].' · '.$c['estado'].' · Multipuerto '.$c['referencia_multipuerto'],
            'Establecimiento: '.$c['establecimiento'].' · CSP '.$c['csp'],
            'Región: '.$c['region'].' · Provincia: '.$c['provincia_planta'].' · Comuna: '.$c['comuna_planta'],
            'Agente: '.$c['agente'].' · RUT '.$c['rut_agente'], 'Exportador: '.$c['exportador'].' · RUT '.$c['rut_exportador'],
            'Nave / vuelo: '.$c['nave_vuelo'].' · Destino: '.$c['destino_ciudad'].' / '.$c['destino_pais'], 'Consignatario: '.$c['consignatario'],
            'Puerto embarque: '.$c['puerto_embarque'].' · Oficina SAG puerto: '.$c['oficina_sag_puerto'],
            'Contenedor: '.$c['contenedor'].' · Sello: '.$c['sello'].' · Ubicación: '.$c['ubicacion_sello'],
            'Guías: '.$c['guias'].' · Patentes: '.$c['patente'].' / '.$c['patente_trasera']];
        $observaciones = 'Pallets: '.$c['pallets'].' · Total: '.$c['total'].' cajas / '.$c['total_kilos'].' kg. Inspeccionado: '.$c['inspeccionado'].' · NIMF 15 verificada: '.$c['nimf15'].'. Tratamiento: '.$c['tratamiento'].' '.$c['detalle_tratamiento'].'. '.$c['motivo_contingencia'].' '.$c['observaciones'].' '.$c['nota'];
        $desbordes = [];
        foreach ($encabezado as &$texto) {
            $lineas = $pdf->envolver($texto, 794, 8);
            if (count($lineas) > 1) {
                $desbordes[] = $texto;
                $texto = $lineas[0].' (ver antecedentes)';
            }
        }
        unset($texto);
        $pie = $pdf->envolver($observaciones.' '.implode(' ', $desbordes), 794, 8);
        foreach (array_slice($datos['secciones'], 0, 2) as $indice => $seccion) {
            $anchoCol = 794 / count($seccion['columnas']);
            $bloques = [];
            $actual = [];
            $altura = 0;
            $maximo = $indice === 0 ? 160 : 300;
            foreach ($seccion['filas'] ?: array_fill(0, $indice === 0 ? 5 : 10, array_fill(0, count($seccion['columnas']), '')) as $fila) {
                $lineas = array_map(fn ($v) => $pdf->envolver((string) $v, $anchoCol - 8, 7), $fila);
                $h = max(18, max(array_map('count', $lineas)) * 9 + 5);
                if ($actual && $altura + $h > $maximo) {
                    $bloques[] = $actual;
                    $actual = [];
                    $altura = 0;
                }
                $actual[] = ['lineas' => $lineas, 'alto' => $h];
                $altura += $h;
            }
            $bloques[] = $actual;
            foreach ($bloques as $bloque) {
                $p = '';
                $y = 568;
                foreach ($indice === 0 ? $encabezado : ['ANEXO POR PALLET · '.$c['codigo'], 'N° interno '.$c['numero'].' · Embarque '.$c['solicitud'].' · '.$c['fecha'], 'Establecimiento: '.$c['establecimiento'].' · CSP '.$c['csp']] as $j => $texto) {
                    foreach ($pdf->envolver($texto, 794, $j === 0 ? 11 : 8) as $linea) {
                        $p .= $pdf->texto(24, $y, $j === 0 ? 11 : 8, $linea, $j === 0);
                        $y -= 13;
                    }
                }
                $y -= 10;
                foreach ($seccion['columnas'] as $j => $nombre) {
                    foreach ($pdf->envolver($nombre, $anchoCol - 8, 7) as $k => $linea) {
                        $p .= $pdf->texto(28 + $j * $anchoCol, $y - $k * 9, 7, $linea, true);
                    }
                }
                $y -= 32;
                foreach ($bloque as $fila) {
                    foreach ($fila['lineas'] as $j => $lineas) {
                        foreach ($lineas as $k => $linea) {
                            $p .= $pdf->texto(28 + $j * $anchoCol, $y - 12 - $k * 9, 7, $linea);
                        }
                        $p .= sprintf("0.3 w %.2F %.2F %.2F %.2F re S\n", 24 + $j * $anchoCol, $y - $fila['alto'], $anchoCol, $fila['alto']);
                    }
                    $y -= $fila['alto'];
                }
                foreach (array_slice($pie, 0, 4) as $k => $linea) {
                    $p .= $pdf->texto(24, 108 - $k * 10, 8, $linea);
                }
                $p .= $pdf->texto(24, 54, 8, 'Despachador: '.$c['responsable'].' · Firma: ____________________');
                $p .= $pdf->texto(24, 38, 8, 'Verificación SAG puerto: Folio __________ Fecha __________ Aprobado / Rechazado __________ Inspector y firma __________');
                $p .= $pdf->texto(24, 24, 8, 'Observación SAG puerto: _______________________________________________________________________');
                $paginas[] = $p;
            }
        }
        foreach (array_chunk(array_slice($pie, 4), 40) as $lineas) {
            $p = $pdf->texto(24, 566, 11, 'ANTECEDENTES DE DESPACHO · '.$c['codigo'].' · N° '.$c['numero'], true);
            foreach ($lineas as $i => $linea) {
                $p .= $pdf->texto(24, 540 - $i * 12, 8, $linea);
            }
            $paginas[] = $p;
        }
        foreach ($paginas as $i => &$pagina) {
            $pagina .= $pdf->texto(750, 14, 7, 'Hoja '.($i + 1).' de '.count($paginas));
        }
        unset($pagina);

        return $pdf->generar($paginas, [842, 595]);
    }
}
