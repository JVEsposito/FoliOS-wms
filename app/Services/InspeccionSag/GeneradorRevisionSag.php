<?php

namespace App\Services\InspeccionSag;

use App\Services\Documentos\DocumentoPdfPlanta;
use DateTimeImmutable;

class GeneradorRevisionSag
{
    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    public function descripcionLote(array $lote, array $datos): string
    {
        $muestreo = ['N' => 'Normal', 'PE' => 'Por etapa'][$datos['muestreo']];
        $seleccion = ['T' => 'Tradicional', 'M' => 'Manual', 'A' => 'Automática'][$datos['seleccion']];
        $despacho = ['I' => 'Inmediato', 'P' => 'Pendiente', 'D' => 'Distancia'][$datos['despacho']];
        $pallets = [];
        foreach ($lote['pallets'] as $i => $pallet) {
            $pallets[] = [$i + 1, $pallet['folio'], $pallet['cajas'], $pallet['acumulado'],
                $pallet['premuestreo'] ?? '', $pallet['cajas_premuestreo'] ?? '',
                $pallet['inspeccion'] ?? '', $pallet['cajas_inspeccion'] ?? ''];
        }

        return $this->documento($this->tabla(
            'Planilla de descripción del lote',
            [
                'SAG / USDA · Lote '.$datos['lote'].' · SIF '.$lote['sif'].' · Planta '.$lote['planta'].' · '.$this->fecha($lote['fecha']),
                'Especie '.$datos['especie'].' ('.$lote['especie_codigo'].') · Destino '.$lote['destino'],
                'Muestreo '.$muestreo.' · Selección '.$seleccion.' · Despacho '.$despacho,
                count($pallets).' pallets · '.$this->cantidad($lote['total_cajas']).' cajas en el lote',
            ],
            ['N.º', 'Folio pallet', 'Cajas', 'Acumulado', 'Cert. premuestreo', 'Cajas', 'Cert. inspección', 'Cajas'],
            [28, 80, 49, 65, 125, 39, 120, 41],
            $pallets,
        ));
    }

    public function mexico(array $revision): string
    {
        $metadatos = [
            'Packing list '.$revision['archivo'].($revision['hoja'] ? ' · Hoja '.$revision['hoja'] : ''),
            $this->cantidad($revision['total_cajas']).' cajas · '.count($revision['filas']).' líneas · '.$revision['filas_con_observaciones'].' con observaciones',
            'Fuente '.$revision['fuente'],
        ];
        if ($revision['consulta_hasta']) {
            $desde = new DateTimeImmutable($revision['consulta_desde'])->format('d-m-Y H:i');
            $hasta = new DateTimeImmutable($revision['consulta_hasta'])->format('d-m-Y H:i');
            $metadatos[] = 'Consulta SAG '.$desde.($desde === $hasta ? '' : ' a '.$hasta).' (hora de planta)';
        }
        $porFecha = array_map(fn (array $fila): array => [
            $this->fecha($fila['fecha']), $this->cantidad($fila['cajas']),
        ], $revision['fechas']);
        $detalle = array_map(function (array $fila): array {
            $registro = array_map(fn (array $r): string => trim($r['especie'].' '.$r['variedad'].' '.$r['clasificacion']), $fila['registro_sag']);
            $observaciones = implode(' ', $fila['observaciones']);
            if ($registro !== [] && $fila['observaciones'] !== []) {
                $observaciones .= ' SAG informa '.mb_strimwidth(implode(' · ', $registro), 0, 240, '…').'.';
            }

            return [$fila['fila'], $fila['folio'], $fila['sdp'] ?? $fila['sdp_original'],
                $this->fecha($fila['fecha'] ?? '—'), $fila['cajas'],
                trim($fila['especie'].' '.$fila['variedad']), implode(', ', $fila['clasificaciones']), $observaciones];
        }, $revision['filas']);

        return $this->documento([
            ...$this->tabla('México · Cajas por fecha de embalaje', $metadatos, ['Fecha de embalaje', 'Cajas'], [385, 162], $porFecha),
            ...$this->tabla('México · Revisión de sitios de producción', $metadatos,
                ['Fila', 'Folio', 'SdP', 'Fecha', 'Cajas', 'Especie / variedad', 'Clase SAG', 'Observaciones'],
                [32, 61, 64, 64, 45, 78, 52, 151], $detalle),
        ]);
    }

    private function tabla(string $titulo, array $metadatos, array $cabeceras, array $anchos, array $filas): array
    {
        $paginas = [];
        $cabecera = $this->pdf->texto(24, 808, 19, $titulo, true);
        $y = 787;
        foreach ($metadatos as $texto) {
            foreach ($this->pdf->envolver($texto, 547, 10) as $linea) {
                $cabecera .= $this->pdf->texto(24, $y, 10, $linea);
                $y -= 13;
            }
        }
        $y -= 6;
        $x = 24;
        $cabecera .= sprintf("0.93 g 24 %.2F 547 21 re f\n", $y - 8);
        foreach ($cabeceras as $indice => $texto) {
            $cabecera .= $this->pdf->texto($x + 3, $y, 8, $texto, true);
            $x += $anchos[$indice];
        }
        $inicio = $y - 18;
        $y = $inicio;
        $pagina = $cabecera;
        foreach ($filas as $fila) {
            $celdas = [];
            foreach ($fila as $indice => $valor) {
                $celdas[] = $this->pdf->envolver((string) $valor, $anchos[$indice] - 6, 8);
            }
            $alto = max(array_map('count', $celdas)) * 10 + 6;
            if ($y - $alto < 32) {
                $paginas[] = $pagina;
                $pagina = $cabecera;
                $y = $inicio;
            }
            $x = 24;
            foreach ($celdas as $indice => $lineas) {
                foreach ($lineas as $n => $linea) {
                    $pagina .= $this->pdf->texto($x + 3, $y - $n * 10, 8, $linea);
                }
                $x += $anchos[$indice];
            }
            $y -= $alto;
            $pagina .= sprintf("0.76 G 0.3 w 24 %.2F m 571 %.2F l S\n", $y + 7, $y + 7);
        }
        $paginas[] = $pagina;

        return $paginas;
    }

    private function documento(array $paginas): string
    {
        $total = count($paginas);
        foreach ($paginas as $i => $pagina) {
            $paginas[$i] = $pagina.$this->pdf->texto(24, 23, 8, 'FoliOS · Página '.($i + 1).' de '.$total);
        }

        return $this->pdf->generar($paginas, conLogo: false);
    }

    private function cantidad(int $cajas): string
    {
        return number_format($cajas, 0, ',', '.');
    }

    private function fecha(string $fecha): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha) ? implode('-', array_reverse(explode('-', $fecha))) : $fecha;
    }
}
