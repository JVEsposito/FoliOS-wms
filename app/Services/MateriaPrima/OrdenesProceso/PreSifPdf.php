<?php

namespace App\Services\MateriaPrima\OrdenesProceso;

use App\Services\Documentos\DocumentoPdfPlanta;

/** PRE-SIF en PDF horizontal, con las mismas columnas que la planilla de planta. */
class PreSifPdf
{
    private const ANCHO = 842;

    private const ALTO = 595;

    private const MARGEN = 20;

    private const ALTO_FILA = 18;

    private const FILAS_POR_PAGINA = 24;

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    /** @param array<string, mixed> $planilla */
    public function generar(array $planilla): string
    {
        $lineas = [];
        foreach ($planilla['filas'] as $indice => $valores) {
            if ($planilla['colacion'] && $planilla['colacion']['despues_de'] === $indice) {
                $lineas[] = ['separador' => $planilla['colacion']['texto']];
            }
            $lineas[] = ['valores' => $valores];
        }
        if ($planilla['colacion'] && $planilla['colacion']['despues_de'] >= count($planilla['filas'])) {
            $lineas[] = ['separador' => $planilla['colacion']['texto']];
        }
        $t = $planilla['totales'];
        $total = array_fill(0, count(PlanillaPreSif::COLUMNAS), '');
        [$total[2], $total[11], $total[12], $total[13]] = ['TOTAL', $t['bins'], $t['totes'], $t['kilos']];
        $lineas[] = ['valores' => $total, 'negrita' => true];

        $paginas = array_chunk($lineas, self::FILAS_POR_PAGINA);

        return $this->pdf->generar(array_map(
            fn (array $pagina, int $indice): string => $this->pagina($planilla['encabezado'], $pagina, $indice + 1, count($paginas)),
            $paginas,
            array_keys($paginas),
        ), [self::ANCHO, self::ALTO]);
    }

    /**
     * @param  array<string, string>  $e
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function pagina(array $e, array $lineas, int $numero, int $total): string
    {
        $ancho = self::ANCHO - 2 * self::MARGEN;
        $c = "0 g 0 G 0.4 w\n";
        $c .= 'q 96 0 0 37 '.self::MARGEN.' 540 cm /Logo Do Q'."\n";
        $c .= $this->pdf->texto(150, 560, 12, $e['titulo'], true);
        $c .= $this->pdf->texto(150, 544, 8.5, "Orden {$e['orden']} · Fecha {$e['fecha']} · Versión {$e['version']} · Turno {$e['turno']} · Línea {$e['linea']}");
        $c .= $this->pdf->texto(150, 532, 8.5, 'CSP '.($e['csp'] ?: '—')." · Horario inicio {$e['horario_inicio']} · Vuelta de colación ".($e['vuelta_colacion'] ?: '—'));

        $escala = $ancho / array_sum(PlanillaPreSif::ANCHOS);
        $y = 488;
        $x = self::MARGEN;
        foreach (PlanillaPreSif::COLUMNAS as $i => $titulo) {
            $w = PlanillaPreSif::ANCHOS[$i] * $escala;
            $c .= sprintf("%.2F %.2F %.2F 28 re S\n", $x, $y, $w);
            $c .= $this->celda($x, $y, $w, 28, $titulo, 5.5, true);
            $x += $w;
        }
        foreach ($lineas as $linea) {
            $y -= self::ALTO_FILA;
            if (isset($linea['separador'])) {
                $c .= sprintf("0.9 g %.2F %.2F %.2F %d re f 0 g\n", self::MARGEN, $y, $ancho, self::ALTO_FILA);
                $c .= sprintf("%.2F %.2F %.2F %d re S\n", self::MARGEN, $y, $ancho, self::ALTO_FILA);
                $c .= $this->pdf->texto(self::MARGEN + $ancho / 2 - 90, $y + 6, 8, $linea['separador'], true);

                continue;
            }
            $x = self::MARGEN;
            foreach ($linea['valores'] as $i => $valor) {
                $w = PlanillaPreSif::ANCHOS[$i] * $escala;
                $c .= sprintf("%.2F %.2F %.2F %d re S\n", $x, $y, $w, self::ALTO_FILA);
                $c .= $this->celda($x, $y, $w, self::ALTO_FILA, $this->texto($valor), 6.5, $linea['negrita'] ?? false);
                $x += $w;
            }
        }
        $c .= $this->pdf->texto(self::ANCHO - 90, 14, 7, "Hoja {$numero} de {$total}");

        return $c;
    }

    private function texto(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (is_float($valor)) {
            return number_format($valor, fmod($valor, 1.0) === 0.0 ? 0 : 1, ',', '.');
        }

        return (string) $valor;
    }

    private function celda(float $x, float $y, float $ancho, float $alto, string $valor, float $tamano, bool $negrita = false): string
    {
        $palabras = count(preg_split('/\s+/u', trim($valor)) ?: ['']);
        do {
            $lineas = $this->pdf->envolver($valor, $ancho - 4, $tamano);
            // Achica antes de cortar una palabra (una comuna o una fecha) en dos líneas.
            if (count($lineas) * ($tamano + 1) <= $alto - 2 && count($lineas) <= $palabras) {
                break;
            }
            $tamano -= 0.25;
        } while ($tamano > 4.5);
        $max = max(1, (int) floor(($alto - 2) / ($tamano + 1)));
        $c = '';
        foreach (array_slice($lineas, 0, $max) as $i => $linea) {
            $c .= $this->pdf->texto($x + 2, $y + $alto - $tamano - 1.5 - $i * ($tamano + 1), $tamano, $linea, $negrita);
        }

        return $c;
    }
}
