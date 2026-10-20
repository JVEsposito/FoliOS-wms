<?php

namespace App\Services\Materiales;

/**
 * Disposición de la tarja de armado, común a PDF, ZPL y NiceLabel. Trabaja en las unidades de
 * cada formato (puntos, dots o micrómetros): recibe ancho y alto de la etiqueta y devuelve
 * líneas, textos con su tamaño de fuente y la caja del código de barras, con origen arriba a
 * la izquierda.
 *
 * En etiqueta vertical o cuadrada, una fila por dato, en el orden de la tarja de bodega:
 * proveedor, cliente, bodega, código, descripción, fecha y cantidad, lote y folio. En etiqueta
 * apaisada, los mismos datos en cinco filas.
 */
final class DisenoTarjaArmado
{
    public const ETIQUETA_FOLIO = 'FOLIO ÚNICO DE ARMADO';

    /**
     * @param  array<string, mixed>  $tarja
     * @return array{
     *     grosor: float,
     *     lineas: list<array{x1: float, y1: float, x2: float, y2: float}>,
     *     textos: list<array{nombre: string, texto: string, x: float, y: float, ancho: float, alto: float, tamano: float, negrita: bool, centrado: bool}>,
     *     codigo: array{valor: string, x: float, y: float, ancho: float, alto: float}
     * }
     */
    public static function disponer(array $tarja, float $ancho, float $alto): array
    {
        $dato = fn (string $clave): string => trim((string) ($tarja[$clave] ?? '')) ?: '—';
        $filas = $alto >= $ancho * 0.9
            ? [
                [1.35, [[1, 'titulo', 'Proveedor', $dato('proveedor')]]],
                [1.0, [[1, 'titulo', 'Cliente', $dato('cliente')]]],
                [0.9, [[1, 'titulo', 'Bodega', $dato('bodega')]]],
                [1.0, [[1, 'campo', 'CÓDIGO', $dato('codigo')]]],
                [1.1, [[1, 'campo', 'DESCRIPCIÓN', $dato('descripcion')]]],
                [1.0, [[1, 'campo', 'FECHA', $dato('fecha')], [1, 'campo', 'CANTIDAD', $dato('cantidad')]]],
                [1.0, [[1, 'campo', 'LOTE', $dato('lote')]]],
                [2.7, [[1, 'folio', self::ETIQUETA_FOLIO, $dato('folio')]]],
            ]
            : [
                [1.1, [
                    [0.8, 'titulo', 'Proveedor', $dato('proveedor')],
                    [1.6, 'titulo', 'Cliente', $dato('cliente')],
                    [1.4, 'titulo', 'Bodega', $dato('bodega')],
                ]],
                [1.0, [
                    [1.3, 'campo', 'CÓDIGO', $dato('codigo')],
                    [0.9, 'campo', 'FECHA', $dato('fecha')],
                    [0.8, 'campo', 'CANTIDAD', $dato('cantidad')],
                ]],
                [1.0, [[1, 'campo', 'DESCRIPCIÓN', $dato('descripcion')]]],
                [1.0, [[1, 'campo', 'LOTE', $dato('lote')]]],
                [2.4, [[1, 'folio', self::ETIQUETA_FOLIO, $dato('folio')]]],
            ];

        $lado = min($ancho, $alto);
        $margen = $lado * 0.035;
        $grosor = max($lado * 0.006, 0.0001);
        $x0 = $margen;
        $y0 = $margen;
        $anchoUtil = $ancho - ($margen * 2);
        $altoUtil = $alto - ($margen * 2);
        $unidad = $altoUtil / array_sum(array_column($filas, 0));
        $relleno = min($unidad * 0.12, $anchoUtil * 0.02);

        $lineas = [
            ['x1' => $x0, 'y1' => $y0, 'x2' => $x0 + $anchoUtil, 'y2' => $y0],
            ['x1' => $x0 + $anchoUtil, 'y1' => $y0, 'x2' => $x0 + $anchoUtil, 'y2' => $y0 + $altoUtil],
            ['x1' => $x0, 'y1' => $y0 + $altoUtil, 'x2' => $x0 + $anchoUtil, 'y2' => $y0 + $altoUtil],
            ['x1' => $x0, 'y1' => $y0, 'x2' => $x0, 'y2' => $y0 + $altoUtil],
        ];
        $textos = [];
        $codigo = null;
        $y = $y0;

        foreach ($filas as $indiceFila => [$peso, $celdas]) {
            $altoFila = $peso * $unidad;
            if ($indiceFila > 0) {
                $lineas[] = ['x1' => $x0, 'y1' => $y, 'x2' => $x0 + $anchoUtil, 'y2' => $y];
            }
            $pesoCeldas = array_sum(array_column($celdas, 0));
            $x = $x0;
            foreach ($celdas as $indiceCelda => [$pesoCelda, $tipo, $etiqueta, $valor]) {
                $anchoCelda = $anchoUtil * $pesoCelda / $pesoCeldas;
                if ($indiceCelda > 0) {
                    $lineas[] = ['x1' => $x, 'y1' => $y, 'x2' => $x, 'y2' => $y + $altoFila];
                }
                $interiorX = $x + $relleno;
                $interiorAncho = $anchoCelda - ($relleno * 2);

                if ($tipo === 'titulo') {
                    $tamano = self::ajustar($valor, $interiorAncho, $altoFila * 0.6, true);
                    $textos[] = self::texto($etiqueta, $valor, $interiorX, $y, $interiorAncho, $altoFila, $tamano, true, true);
                } elseif ($tipo === 'campo') {
                    $tamanoEtiqueta = self::ajustar($etiqueta, $interiorAncho, $altoFila * 0.26, false);
                    $altoEtiqueta = $tamanoEtiqueta * 1.3;
                    $yEtiqueta = $y + ($relleno * 0.6);
                    $textos[] = self::texto($etiqueta, $etiqueta, $interiorX, $yEtiqueta, $interiorAncho, $altoEtiqueta, $tamanoEtiqueta, false, false);
                    $yValor = $yEtiqueta + $altoEtiqueta;
                    $altoValor = ($y + $altoFila - ($relleno * 0.4)) - $yValor;
                    $tamanoValor = self::ajustar($valor, $interiorAncho, min($altoFila * 0.46, $altoValor * 0.85), true);
                    $lineasValor = self::dividir($valor);
                    $tamanoDosLineas = $lineasValor === null ? 0 : min(
                        self::ajustar($lineasValor[0], $interiorAncho, $altoValor * 0.44, true),
                        self::ajustar($lineasValor[1], $interiorAncho, $altoValor * 0.44, true),
                    );
                    if ($lineasValor !== null && $tamanoDosLineas > $tamanoValor * 1.2) {
                        // Un texto largo, como la descripción, se lee mejor en dos líneas.
                        foreach ($lineasValor as $indiceLinea => $lineaValor) {
                            $textos[] = self::texto(
                                $etiqueta.' valor '.($indiceLinea + 1),
                                $lineaValor,
                                $interiorX,
                                $yValor + ($indiceLinea * $altoValor / 2),
                                $interiorAncho,
                                $altoValor / 2,
                                $tamanoDosLineas,
                                true,
                                false,
                            );
                        }
                    } else {
                        $textos[] = self::texto($etiqueta.' valor', $valor, $interiorX, $yValor, $interiorAncho, $altoValor, $tamanoValor, true, false);
                    }
                } else {
                    $tamanoEtiqueta = self::ajustar($etiqueta, $interiorAncho, $altoFila * 0.11, false);
                    $altoEtiqueta = $tamanoEtiqueta * 1.4;
                    $yEtiqueta = $y + ($relleno * 0.6);
                    $textos[] = self::texto('Folio etiqueta', $etiqueta, $interiorX, $yEtiqueta, $interiorAncho, $altoEtiqueta, $tamanoEtiqueta, false, true);
                    $tamanoFolio = self::ajustar($valor, $interiorAncho, $altoFila * 0.17, true);
                    $altoFolio = $tamanoFolio * 1.35;
                    $yFolio = $y + $altoFila - ($relleno * 0.5) - $altoFolio;
                    $yCodigo = $yEtiqueta + $altoEtiqueta + ($relleno * 0.5);
                    $codigo = [
                        'valor' => $valor,
                        'x' => $interiorX,
                        'y' => $yCodigo,
                        'ancho' => $interiorAncho,
                        'alto' => max($relleno, $yFolio - $yCodigo - ($relleno * 0.5)),
                    ];
                    $textos[] = self::texto('Folio', $valor, $interiorX, $yFolio, $interiorAncho, $altoFolio, $tamanoFolio, true, true);
                }
                $x += $anchoCelda;
            }
            $y += $altoFila;
        }

        return ['grosor' => $grosor, 'lineas' => $lineas, 'textos' => $textos, 'codigo' => $codigo];
    }

    /**
     * Ancho aproximado del texto en Helvetica/Arial, en unidades del tamaño de fuente.
     */
    public static function anchoRelativo(string $texto, bool $negrita): float
    {
        $ancho = 0.0;
        foreach (mb_str_split($texto) as $caracter) {
            $ancho += match (true) {
                ctype_digit($caracter) => 0.556,
                str_contains(' .,:;·\'|', $caracter) => 0.278,
                str_contains('-/()', $caracter) => 0.333,
                in_array($caracter, ['M', 'W', 'Ñ', 'm', 'w'], true) => $negrita ? 0.89 : 0.83,
                mb_strtoupper($caracter) === $caracter && mb_strtolower($caracter) !== $caracter => $negrita ? 0.72 : 0.67,
                mb_strtolower($caracter) === $caracter && mb_strtoupper($caracter) !== $caracter => $negrita ? 0.58 : 0.52,
                default => 0.6,
            };
        }

        return max(0.278, $ancho);
    }

    /**
     * Divide el texto en dos líneas por el espacio más cercano a la mitad.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function dividir(string $texto): ?array
    {
        $palabras = preg_split('/\s+/', trim($texto)) ?: [];
        if (count($palabras) < 2) {
            return null;
        }
        $mejor = null;
        for ($corte = 1; $corte < count($palabras); $corte++) {
            $lineas = [
                implode(' ', array_slice($palabras, 0, $corte)),
                implode(' ', array_slice($palabras, $corte)),
            ];
            $ancho = max(self::anchoRelativo($lineas[0], true), self::anchoRelativo($lineas[1], true));
            if ($mejor === null || $ancho < $mejor[0]) {
                $mejor = [$ancho, $lineas];
            }
        }

        return $mejor[1];
    }

    /** Tamaño de fuente que cabe en el ancho, sin pasar del máximo. */
    private static function ajustar(string $texto, float $ancho, float $maximo, bool $negrita): float
    {
        return max(0.0001, min($maximo, ($ancho * 0.96) / self::anchoRelativo($texto, $negrita)));
    }

    /** @return array{nombre: string, texto: string, x: float, y: float, ancho: float, alto: float, tamano: float, negrita: bool, centrado: bool} */
    private static function texto(
        string $nombre,
        string $texto,
        float $x,
        float $y,
        float $ancho,
        float $alto,
        float $tamano,
        bool $negrita,
        bool $centrado,
    ): array {
        return compact('nombre', 'texto', 'x', 'y', 'ancho', 'alto', 'tamano', 'negrita', 'centrado');
    }
}
