<?php

namespace App\Services\MateriaPrima;

use App\Services\Documentos\DocumentoPdfPlanta;
use Illuminate\Support\Collection;

class RegistroHidrocoolerPdf
{
    public function __construct(private readonly RegistroControlHidrocooler $registro, private readonly DocumentoPdfPlanta $pdf) {}

    public function generar(Collection $procesos): string
    {
        return $this->documento($this->registro->paginas($procesos));
    }

    public function generarEnBlanco(): string
    {
        return $this->documento($this->registro->paginas(collect(), true));
    }

    private function documento(array $hojas): string
    {
        return $this->pdf->generar(array_map(fn ($h, $i) => $this->pagina($h, $i + 1, count($hojas)), $hojas, array_keys($hojas)), [1191, 842]);
    }

    private function pagina(array $hoja, int $numero, int $total): string
    {
        $f = $hoja['formato'];
        $c = "0 g 0 G 0.4 w\n20 746 1151 76 re S\n180 746 m 180 822 l S\n966 746 m 966 822 l S\n966 770 m 1171 770 l S\n966 796 m 1171 796 l S\n";
        $c .= "q 140 0 0 54 30 757 cm /Logo Do Q\n";
        $c .= $this->pdf->texto(300, 790, 17, 'REGISTRO CONTROL HIDROCOOLER', true);
        $c .= $this->pdf->texto(192, 760, 8, $f['localidad']);
        $c .= $this->pdf->texto(978, 806, 9, 'Código: '.$f['codigo'], true);
        $c .= $this->pdf->texto(978, 780, 9, 'Versión: '.$f['version']);
        $c .= $this->pdf->texto(978, 754, 9, 'Fecha: '.date('d-m-Y', strtotime($f['fecha_vigencia'])));
        if (! $hoja['anexo']) {
            $x = 20;
            $escala = 1151 / array_sum(RegistroControlHidrocooler::ANCHOS);
            foreach (RegistroControlHidrocooler::COLUMNAS as $i => $titulo) {
                $ancho = RegistroControlHidrocooler::ANCHOS[$i] * $escala;
                $c .= "{$x} 688 {$ancho} 44 re S\n";
                $c .= $this->celda($x, 688, $ancho, 44, $titulo, 6.5, true);
                $x += $ancho;
            }
            for ($fila = 0; $fila < RegistroControlHidrocooler::FILAS; $fila++) {
                $x = 20;
                $y = 688 - ($fila + 1) * 22;
                foreach ($hoja['filas'][$fila] ?? array_fill(0, 22, '') as $i => $valor) {
                    $ancho = RegistroControlHidrocooler::ANCHOS[$i] * $escala;
                    $c .= "{$x} {$y} {$ancho} 22 re S\n";
                    $c .= $this->celda($x, $y, $ancho, 22, $valor, $i === 0 ? 6 : 7);
                    $x += $ancho;
                }
            }
            $c .= $this->pdf->texto(20, 148, 8, 'Observaciones', true);
            foreach ($hoja['notas'] as $i => $nota) {
                $c .= $this->pdf->texto(24, 134 - $i * 10, 7.5, $nota);
            }
        } else {
            $c .= $this->pdf->texto(20, 720, 11, 'Observaciones - continuación de hoja '.$hoja['origen'], true);
            foreach ($hoja['notas'] as $i => $nota) {
                $c .= $this->pdf->texto(24, 700 - $i * 10, 7.5, $nota);
            }
        }
        $c .= "130 44 m 440 44 l S\n750 44 m 1060 44 l S\n";
        $c .= $this->pdf->texto(235, 30, 9, 'Jefe de Calidad');
        $c .= $this->pdf->texto(860, 30, 9, 'Responsable');
        $c .= $this->pdf->texto(1080, 14, 7, 'Hoja '.$numero.' de '.$total);

        return $c;
    }

    private function celda(float $x, float $y, float $ancho, float $alto, string $valor, float $tamano, bool $negrita = false): string
    {
        do {
            $lineas = $this->pdf->envolver($valor, $ancho - 6, $tamano);
            if (count($lineas) * ($tamano + 1) <= $alto - 3) {
                break;
            }
            $tamano -= 0.25;
        } while ($tamano > 5);
        // Los valores excepcionalmente extensos conservan su texto en el anexo común.
        $max = max(1, (int) floor(($alto - 3) / ($tamano + 1)));
        if (count($lineas) > $max) {
            $lineas = array_slice($lineas, 0, $max);
            $lineas[$max - 1] = 'Ver anexo';
        }
        $c = '';
        foreach ($lineas as $i => $linea) {
            $c .= $this->pdf->texto($x + 3, $y + $alto - $tamano - 2 - $i * ($tamano + 1), $tamano, $linea, $negrita);
        }

        return $c;
    }
}
