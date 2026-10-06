<?php

namespace App\Services\Documentos;

use DomainException;

class RegistroRecepcionFrutaEmbaladaPdf
{
    private const COLUMNAS = [
        ['N°', 24, 'orden'], ['Folio', 104, 'folio'], ['Embalaje', 120, 'embalaje'],
        ['Especie', 75, 'especie'], ['Variedad', 99, 'variedad'], ['CSG', 59, 'csg'],
        ['CSP', 59, 'csp'], ['Calibre', 60, 'calibre'], ['Cajas', 50, 'cantidad_cajas'], ['T° (°C)', 44, 'temperatura_pulpa_c'],
    ];

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    public function generar(array $datos, array $formato, bool $blanco = false): string
    {
        $filas = $blanco ? [] : $datos['pallets'];
        $hojas = array_chunk($filas, 20) ?: [[]];
        $observaciones = $this->pdf->envolver((string) ($datos['observacion'] ?? ''), 780, 8);
        $extras = array_chunk(array_slice($observaciones, 4), 32);
        $paginas = [];
        $totalPaginas = count($hojas) + count($extras);
        foreach ($hojas as $indice => $pallets) {
            $contenido = $this->encabezado($datos, $formato, $indice + 1, $totalPaginas);
            $x = 24;
            $y = 440;
            foreach (self::COLUMNAS as [$titulo, $ancho]) {
                $contenido .= $this->pdf->texto($x + 3, $y + 5, 8, $titulo, true);
                $x += $ancho;
            }
            $contenido .= "0.4 w 24 440 m 818 440 l S\n";
            for ($fila = 0; $fila < 20; $fila++) {
                $x = 24;
                $y = 440 - ($fila + 1) * 15;
                foreach (self::COLUMNAS as [, $ancho, $campo]) {
                    $valor = (string) ($pallets[$fila][$campo] ?? '');
                    if ($campo === 'temperatura_pulpa_c' && $valor !== '') {
                        $valor = number_format((float) $valor, 2, ',', '');
                    }
                    $contenido .= $this->celda($valor, $x + 3, $y + 9, $ancho - 6);
                    $contenido .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x, $y, $x, $y + 15);
                    $x += $ancho;
                }
                $contenido .= sprintf("24 %.2F m 818 %.2F l S 818 %.2F m 818 %.2F l S\n", $y, $y, $y, $y + 15);
            }
            $contenido .= $this->pdf->texto(24, 126, 9, $blanco ? 'Total de cajas hoja: __________     Total recepción: __________' : 'Total de cajas hoja: '.array_sum(array_column($pallets, 'cantidad_cajas')).'     Total recepción: '.array_sum(array_column($filas, 'cantidad_cajas')), true);
            $contenido .= $this->pdf->texto(24, 109, 8, 'Observaciones:', true);
            foreach (array_slice($observaciones, 0, 4) as $n => $texto) {
                $contenido .= $this->pdf->texto(24, 97 - $n * 10, 8, $texto);
            }
            if ($extras !== []) {
                $contenido .= $this->pdf->texto(24, 53, 7, 'Observaciones continúan en el anexo.');
            }
            $paginas[] = $contenido.$this->firmas();
        }
        // No recorta observaciones extensas: sus anexos conservan encabezado y firmas.
        foreach ($extras as $indice => $lineas) {
            $contenido = $this->encabezado($datos, $formato, count($hojas) + $indice + 1, $totalPaginas);
            $contenido .= $this->pdf->texto(24, 435, 11, 'Anexo de observaciones', true);
            foreach ($lineas as $n => $texto) {
                $contenido .= $this->pdf->texto(24, 416 - $n * 11, 8, $texto);
            }
            $paginas[] = $contenido.$this->firmas();
        }

        return $this->pdf->generar($paginas, [842, 595]);
    }

    private function encabezado(array $d, array $f, int $hoja, int $total): string
    {
        $texto = "q 64 0 0 32 24 552 cm /Logo Do Q\n";
        $texto .= $this->pdf->texto(108, 569, 15, 'REGISTRO RECEPCIÓN DE FRUTA EMBALADA', true);
        $texto .= $this->pdf->texto(108, 552, 9, $f['localidad']);
        $texto .= $this->pdf->texto(676, 572, 9, $f['codigo'], true);
        $texto .= $this->pdf->texto(676, 557, 8, 'Versión '.$f['version'].' · '.implode('-', array_reverse(explode('-', $f['fecha_vigencia']))));
        $texto .= $this->pdf->texto(676, 543, 8, "Hoja {$hoja} de {$total}");
        // Lista explícita de versión 1: no imprime guía ni condición SAG.
        $campos = [
            ['Cliente', $d['cliente'] ?? '', 'Planta de origen', $d['planta_origen'] ?? ''],
            ['Servicio', $d['servicio'] ?? '', 'Turno', $d['turno'] ?? ''],
            ['Recepción', $d['recepcion'] ?? '', 'Salida', $d['salida'] ?? ''],
            ['Validador', $d['validador'] ?? '', 'Llega con prefrío', $d['prefrio'] ?? ''],
            ['Chofer', $d['chofer'] ?? '', 'RUT', $d['rut_chofer'] ?? ''],
            ['Patente delantera', $d['patente_delantera'] ?? '', 'Patente del carro', $d['patente_carro'] ?? ''],
        ];
        foreach ($campos as $n => [$a, $b, $c, $e]) {
            $texto .= $this->celda($a.': '.$b, 24, 529 - $n * 13, 392);
            $texto .= $this->celda($c.': '.$e, 430, 529 - $n * 13, 388);
        }

        return $texto;
    }

    private function celda(string $valor, float $x, float $y, float $ancho): string
    {
        for ($tamano = 7; $tamano >= 5; $tamano -= 0.5) {
            $lineas = $this->pdf->envolver($valor, $ancho, $tamano);
            if (count($lineas) <= 2) {
                return implode('', array_map(fn ($linea, $n) => $this->pdf->texto($x, $y - $n * 6, $tamano, $linea), $lineas, array_keys($lineas)));
            }
        }
        throw new DomainException('Un dato es demasiado extenso para el RRFE-01. Revísalo antes de emitir el documento.');
    }

    private function firmas(): string
    {
        return "140 34 m 360 34 l S 480 34 m 700 34 l S\n"
            .$this->pdf->texto(175, 20, 9, 'Supervisor de frío')
            .$this->pdf->texto(518, 20, 9, 'Jefe de frigorífico');
    }
}
