<?php

namespace App\Services\Documentos;

/** Anchos reales de Helvetica (unidades de 1/1000 em) para centrar y alinear texto en un PDF. */
class MetricasHelvetica
{
    /** @var array<string, array<string, int>> */
    private static array $tablas = [];

    public function ancho(string $texto, float $tamano, bool $negrita = false): float
    {
        $tabla = self::$tablas[$negrita ? 'negrita' : 'normal'] ??= $this->tabla($negrita);
        $total = 0;
        foreach (mb_str_split($texto) as $caracter) {
            $base = $tabla[$caracter] ?? null;
            if ($base === null) {
                $simple = iconv('UTF-8', 'ASCII//TRANSLIT', $caracter) ?: '';
                $base = $tabla[$simple[0] ?? ''] ?? 556;
            }
            $total += $base;
        }

        return $total * $tamano / 1000;
    }

    /** Recorta con «…» lo que no cabe en el ancho dado. */
    public function recortar(string $texto, float $ancho, float $tamano, bool $negrita = false): string
    {
        if ($this->ancho($texto, $tamano, $negrita) <= $ancho) {
            return $texto;
        }
        $caracteres = mb_str_split($texto);
        while ($caracteres !== [] && $this->ancho(implode('', $caracteres).'…', $tamano, $negrita) > $ancho) {
            array_pop($caracteres);
        }

        return rtrim(implode('', $caracteres), ' ·').'…';
    }

    /** @return array<string, int> */
    private function tabla(bool $negrita): array
    {
        $mayusculas = $negrita
            ? [722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611]
            : [667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611];
        $minusculas = $negrita
            ? [556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500]
            : [556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500];
        $signos = [' ' => 278, '.' => 278, ',' => 278, ':' => $negrita ? 333 : 278, ';' => $negrita ? 333 : 278, '-' => 333,
            '–' => 556, '/' => 278, '(' => 333, ')' => 333, '·' => 278, '%' => 889, '#' => 556, 'º' => 365, '+' => 584,
            '—' => 1000, '«' => 556, '»' => 556, '…' => 1000, '×' => 584];

        return array_combine(range('A', 'Z'), $mayusculas) + array_combine(range('a', 'z'), $minusculas)
            + array_fill_keys(range('0', '9'), 556) + $signos;
    }
}
