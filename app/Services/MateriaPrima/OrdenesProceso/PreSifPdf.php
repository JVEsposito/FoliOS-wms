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

    private const TECHO_TABLA = 516;

    private const PIE_TABLA = 36;

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

        $cabecera = $this->prepararFila(PlanillaPreSif::COLUMNAS, 5.5);
        $disponible = self::TECHO_TABLA - $cabecera['alto'] - self::PIE_TABLA;
        $paginas = [];
        $pagina = [];
        $ocupado = 0;
        foreach ($lineas as $linea) {
            $linea = isset($linea['separador'])
                ? [...$linea, 'alto' => self::ALTO_FILA]
                : [...$linea, ...$this->prepararFila($linea['valores'], 6.5)];
            if ($pagina !== [] && $ocupado + $linea['alto'] > $disponible) {
                $paginas[] = $pagina;
                $pagina = [];
                $ocupado = 0;
            }
            $pagina[] = $linea;
            $ocupado += $linea['alto'];
        }
        if ($pagina !== []) {
            $paginas[] = $pagina;
        }

        return $this->pdf->generar(array_map(
            fn (array $pagina, int $indice): string => $this->pagina($planilla['encabezado'], $cabecera, $pagina, $indice + 1, count($paginas)),
            $paginas,
            array_keys($paginas),
        ), [self::ANCHO, self::ALTO]);
    }

    /**
     * @param  array<string, string>  $e
     * @param  array<string, mixed>  $cabecera
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function pagina(array $e, array $cabecera, array $lineas, int $numero, int $total): string
    {
        $ancho = self::ANCHO - 2 * self::MARGEN;
        $c = "0 g 0 G 0.4 w\n";
        $c .= 'q 96 0 0 37 '.self::MARGEN.' 540 cm /Logo Do Q'."\n";
        $c .= $this->pdf->texto(150, 560, 12, $e['titulo'], true);
        $c .= $this->pdf->texto(150, 544, 8.5, "Orden {$e['orden']} · Fecha {$e['fecha']} · Versión {$e['version']} · Turno {$e['turno']} · Línea {$e['linea']}");
        $c .= $this->pdf->texto(150, 532, 8.5, 'CSP '.($e['csp'] ?: '—')." · Horario inicio {$e['horario_inicio']} · Vuelta de colación ".($e['vuelta_colacion'] ?: '—'));

        $y = self::TECHO_TABLA - $cabecera['alto'];
        $x = self::MARGEN;
        foreach ($cabecera['celdas'] as $celda) {
            $c .= sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $celda['ancho'], $cabecera['alto']);
            $c .= $this->celda($x, $y, $cabecera['alto'], $celda, true);
            $x += $celda['ancho'];
        }
        foreach ($lineas as $linea) {
            $y -= $linea['alto'];
            if (isset($linea['separador'])) {
                $c .= sprintf("0.9 g %.2F %.2F %.2F %d re f 0 g\n", self::MARGEN, $y, $ancho, self::ALTO_FILA);
                $c .= sprintf("%.2F %.2F %.2F %d re S\n", self::MARGEN, $y, $ancho, self::ALTO_FILA);
                $c .= $this->pdf->texto(self::MARGEN + $ancho / 2 - 90, $y + 6, 8, $linea['separador'], true);

                continue;
            }
            $x = self::MARGEN;
            foreach ($linea['celdas'] as $celda) {
                $c .= sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $celda['ancho'], $linea['alto']);
                $c .= $this->celda($x, $y, $linea['alto'], $celda, $linea['negrita'] ?? false);
                $x += $celda['ancho'];
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

    /**
     * @param  array<int, mixed>  $valores
     * @return array{alto: float, celdas: array<int, array{ancho: float, tamano: float, lineas: array<int, string>}>}
     */
    private function prepararFila(array $valores, float $tamano): array
    {
        $escala = (self::ANCHO - 2 * self::MARGEN) / array_sum(PlanillaPreSif::ANCHOS);
        $alto = (float) self::ALTO_FILA;
        $celdas = [];
        foreach ($valores as $indice => $valor) {
            $texto = $this->texto($valor);
            $ancho = PlanillaPreSif::ANCHOS[$indice] * $escala;
            $fuente = $tamano;
            $palabras = count(preg_split('/\s+/u', trim($texto)) ?: ['']);
            do {
                $lineas = $this->pdf->envolver($texto, $ancho - 4, $fuente);
                // Primero reduce palabras largas; después crece la fila, sin descartar texto.
                if (count($lineas) <= $palabras || $fuente <= 4.5) {
                    break;
                }
                $fuente -= 0.25;
            } while (true);
            $alto = max($alto, count($lineas) * ($fuente + 1) + 4);
            $celdas[] = ['ancho' => $ancho, 'tamano' => $fuente, 'lineas' => $lineas];
        }

        return ['alto' => $alto, 'celdas' => $celdas];
    }

    /** @param array{ancho: float, tamano: float, lineas: array<int, string>} $celda */
    private function celda(float $x, float $y, float $alto, array $celda, bool $negrita = false): string
    {
        $tamano = $celda['tamano'];
        $c = '';
        foreach ($celda['lineas'] as $i => $linea) {
            $c .= $this->pdf->texto($x + 2, $y + $alto - $tamano - 1.5 - $i * ($tamano + 1), $tamano, $linea, $negrita);
        }

        return $c;
    }
}
