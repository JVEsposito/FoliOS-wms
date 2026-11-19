<?php

namespace App\Services\MateriaPrima;

use App\Services\Documentos\DocumentoPdfPlanta;
use Carbon\CarbonImmutable;

class GeneradorInformeRecepcionesCsgPdf
{
    private const MARGEN = 30;

    private const ANCHO = 535;

    private const CUERPO = 8.5;

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    public function generar(array $informe): string
    {
        $periodo = $informe['periodo'];
        $desde = $this->fecha($periodo['desde']);
        $hasta = $this->fecha($periodo['hasta']);
        $encabezado = "q 57 0 0 29 30 784 cm /Logo Do Q\n";
        $encabezado .= $this->pdf->texto(100, 803, 16, 'INFORME DE RECEPCIÓN POR CSG', true);
        $encabezado .= $this->pdf->texto(100, 786, 9, ($desde === $hasta ? $desde : "{$desde} al {$hasta}")
            .' · Temporada '.$informe['temporada']['codigo']);
        $y = 766;
        foreach ($this->pdf->envolver($informe['cliente']['codigo'].' · '.$informe['cliente']['nombre'], self::ANCHO, 10) as $linea) {
            $encabezado .= $this->pdf->texto(self::MARGEN, $y, 10, $linea, true);
            $y -= 13;
        }

        $inicio = ['contenido' => $encabezado, 'y' => $y - 8];
        $pagina = $inicio;
        $paginas = [];
        $this->totales($pagina, $informe['totales']);
        if ($informe['grafico'] !== []) {
            $this->grafico($pagina, $informe['grafico'], count($informe['resumen']));
            $this->tabla($pagina, $paginas, $inicio, 'RESUMEN POR CSG',
                ['CSG' => 85, 'Productor' => 300, 'Lotes' => 55, 'Kg netos' => 95],
                array_map(fn (array $fila): array => [
                    $fila['csg'], $fila['productor'] ?? '—', (string) $fila['lotes'], $this->kilos($fila['kilos']),
                ], $informe['resumen']));
        }
        if ($informe['detalle'] !== []) {
            $pagina['y'] -= 14;
            $this->tabla($pagina, $paginas, $inicio, 'DETALLE DE RECEPCIONES',
                ['Ingreso' => 82, 'Recepción' => 88, 'Guía' => 63, 'Lote' => 54, 'CSG' => 80, 'Variedad' => 99, 'Kg netos' => 69],
                array_map(fn (array $fila): array => [
                    $this->fecha($fila['fecha'], 'd-m-Y H:i'), $fila['recepcion'] ?? '—', $fila['guia'] ?? '—',
                    $fila['lote'] ?? '—', $fila['rotulo_csg'], $fila['variedad'] ?? '—', $this->kilos($fila['kilos']),
                ], $informe['detalle']));
        } elseif ($informe['totales']['recepciones'] === 0) {
            $pagina['contenido'] .= $this->pdf->texto(self::MARGEN, $pagina['y'] - 12, 10, 'Sin recepciones cerradas en el período.');
        }
        $paginas[] = $pagina['contenido'];
        $cantidad = count($paginas);
        foreach ($paginas as $indice => $contenido) {
            $paginas[$indice] = $contenido."0.80 G 30 40 m 565 40 l S 0 G\n"
                .$this->pdf->texto(self::MARGEN, 27, 7.5, 'FoliOS · Fecha de ingreso en hora de planta · Kg por CSG según lotes confirmados.')
                .$this->pdf->texto(508, 27, 7.5, sprintf('Hoja %d de %d', $indice + 1, $cantidad));
        }

        return $this->pdf->generar($paginas);
    }

    private function totales(array &$pagina, array $totales): void
    {
        $y = $pagina['y'];
        $pagina['contenido'] .= sprintf("0.92 0.95 0.96 rg 30 %d 535 43 re f\n", $y - 39);
        foreach ([
            ['Kilos netos Romana', $this->kilos($totales['kilos_netos']).' kg'],
            ['Kilos con CSG', $this->kilos($totales['kilos_con_csg']).' kg'],
            ['Recepciones cerradas', (string) $totales['recepciones']],
            ['CSG con recepción', (string) $totales['csg']],
        ] as $indice => [$etiqueta, $valor]) {
            $x = self::MARGEN + 10 + $indice * 132;
            $pagina['contenido'] .= $this->pdf->texto($x, $y - 10, 8, $etiqueta);
            $pagina['contenido'] .= $this->pdf->texto($x, $y - 29, 13, $valor, true);
        }

        $y -= 56;
        if ($totales['kilos_pendientes'] > 0) {
            $pagina['contenido'] .= $this->pdf->texto(self::MARGEN, $y, 9, 'Pendientes de CSG  '.$this->kilos($totales['kilos_pendientes']).' kg', true);
            $y -= 14;
        }
        if ($totales['kilos_excedentes'] > 0) {
            $pagina['contenido'] .= $this->pdf->texto(self::MARGEN, $y, 9, 'Exceso asignado frente a Romana  '.$this->kilos($totales['kilos_excedentes']).' kg', true);
            $y -= 14;
        }
        $pagina['y'] = $y - 6;
    }

    private function grafico(array &$pagina, array $barras, int $cantidadCsg): void
    {
        $y = $pagina['y'];
        $titulo = count($barras) < $cantidadCsg ? count($barras).' CSG CON MÁS KILOS' : 'KILOS NETOS POR CSG';
        $pagina['contenido'] .= $this->pdf->texto(self::MARGEN, $y, 10, $titulo, true);
        $y -= 17;
        $mayor = $barras[0]['kilos'];
        foreach ($barras as $fila) {
            $etiqueta = $this->pdf->envolver('CSG '.$fila['csg'], 90, self::CUERPO);
            foreach ($etiqueta as $indice => $linea) {
                $pagina['contenido'] .= $this->pdf->texto(self::MARGEN, $y - $indice * 10, self::CUERPO, $linea);
            }
            $pagina['contenido'] .= sprintf("0.90 0.93 0.95 rg 126 %d 317 8 re f\n", $y - 1);
            $pagina['contenido'] .= sprintf("0.16 0.46 0.55 rg 126 %d %.2F 8 re f\n", $y - 1, 317 * $fila['kilos'] / $mayor);
            $pagina['contenido'] .= $this->pdf->texto(454, $y, self::CUERPO, $this->kilos($fila['kilos']).' kg', true);
            $y -= max(16, 10 * count($etiqueta) + 4);
        }
        $pagina['y'] = $y - 4;
    }

    private function tabla(array &$pagina, array &$paginas, array $inicio, string $titulo, array $columnas, array $filas): void
    {
        $encabezado = false;
        $anchos = array_values($columnas);
        foreach ($filas as $indice => $fila) {
            $celdas = array_map(fn (string $texto, int $ancho): array => $this->pdf->envolver($texto, $ancho - 8, self::CUERPO), $fila, $anchos);
            $alto = 6 + max(array_map('count', $celdas)) * 10;
            if ($pagina['y'] - $alto - ($encabezado ? 0 : 35) < 50) {
                $paginas[] = $pagina['contenido'];
                $pagina = $inicio;
                $encabezado = false;
            }
            if (! $encabezado) {
                $y = $pagina['y'];
                $pagina['contenido'] .= $this->pdf->texto(self::MARGEN, $y, 10, $titulo, true);
                $pagina['contenido'] .= sprintf("0.90 0.93 0.94 rg 30 %d 535 17 re f\n", $y - 27);
                $x = self::MARGEN + 4;
                foreach ($columnas as $nombre => $ancho) {
                    $pagina['contenido'] .= $this->pdf->texto($x, $y - 21, self::CUERPO, $nombre, true);
                    $x += $ancho;
                }
                $pagina['y'] -= 31;
                $encabezado = true;
            }
            $y = $pagina['y'];
            if ($indice % 2 === 0) {
                $pagina['contenido'] .= sprintf("0.96 0.97 0.98 rg 30 %d 535 %d re f\n", $y - $alto, $alto);
            }
            $x = self::MARGEN + 4;
            foreach ($celdas as $columna => $lineas) {
                foreach ($lineas as $numero => $linea) {
                    $pagina['contenido'] .= $this->pdf->texto($x, $y - 11 - $numero * 10, self::CUERPO, $linea);
                }
                $x += $anchos[$columna];
            }
            $pagina['y'] -= $alto;
        }
    }

    private function fecha(string $fecha, string $formato = 'd-m-Y'): string
    {
        return CarbonImmutable::parse($fecha, config('app.operational_timezone'))->format($formato);
    }

    private function kilos(float $valor): string
    {
        return number_format($valor, 3, ',', '.');
    }
}
