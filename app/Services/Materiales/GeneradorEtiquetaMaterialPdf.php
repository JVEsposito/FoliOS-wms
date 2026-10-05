<?php

namespace App\Services\Materiales;

class GeneradorEtiquetaMaterialPdf
{
    private const CODE128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $etiquetas
     * @param  array<string, mixed>  $perfil
     */
    public function generar(array $etiquetas, array $perfil, int $copias = 1): string
    {
        [$anchoMm, $altoMm] = $this->dimensiones($perfil);
        $ancho = $this->puntos($anchoMm);
        $alto = $this->puntos($altoMm);
        $paginas = [];

        foreach ($etiquetas as $etiqueta) {
            for ($copia = 0; $copia < $copias; $copia++) {
                $paginas[] = $this->pagina($etiqueta, $ancho, $alto);
            }
        }

        return $this->documento($paginas, $ancho, $alto);
    }

    /** @param array<string, mixed> $etiqueta */
    protected function pagina(array $etiqueta, float $ancho, float $alto): string
    {
        $margen = max(7, $ancho * 0.035);
        $encabezado = max(22, $alto * 0.17);
        $contenido = sprintf("0 0 0 rg 0 %.2F %.2F %.2F re f\n", $alto - $encabezado, $ancho, $encabezado);
        $contenido .= $this->texto(
            $margen,
            $alto - ($encabezado * 0.68),
            max(11, min(22, $encabezado * 0.46)),
            (string) $etiqueta['numero_folio'],
            true,
            '1 1 1',
        );

        $barcodeY = $alto * 0.48;
        $barcodeHeight = max(22, $alto * 0.20);
        $contenido .= $this->codigoBarras(
            (string) $etiqueta['numero_folio'],
            $margen,
            $barcodeY,
            $ancho - ($margen * 2),
            $barcodeHeight,
        );
        $contenido .= $this->texto(
            $margen,
            $barcodeY - max(10, $alto * 0.055),
            max(6, min(10, $alto * 0.035)),
            (string) $etiqueta['numero_folio'],
            false,
        );

        $tamano = max(6, min(10, $alto * 0.035));
        $salto = max(8, $tamano * 1.35);
        $y = $barcodeY - max(22, $alto * 0.13);
        $lineas = [
            $etiqueta['cliente_codigo'].' · '.$etiqueta['cliente_nombre'],
            $etiqueta['item_codigo'].' · '.$etiqueta['item_nombre'],
            'Cantidad: '.$etiqueta['cantidad'].' '.$etiqueta['unidad_medida'],
            ...$this->lineasOrigen($etiqueta),
        ];
        if ($etiqueta['bloqueado']) {
            $lineas[] = 'BLOQUEADO: '.($etiqueta['motivo_bloqueo'] ?: 'Sin motivo');
        }

        foreach ($lineas as $indice => $linea) {
            if ($y < 5) {
                break;
            }
            $texto = (string) $linea;
            $negrita = $indice < 3
                || ($etiqueta['bloqueado'] && $indice === count($lineas) - 1);
            $contenido .= $this->texto(
                $margen,
                $y,
                $this->tamanoDetalle(
                    $texto,
                    $ancho - ($margen * 2),
                    $tamano,
                    $negrita,
                ),
                $texto,
                $negrita,
            );
            $y -= $salto;
        }

        return $contenido;
    }

    protected function codigoBarras(
        string $valor,
        float $x,
        float $y,
        float $ancho,
        float $alto,
        bool $estricto = false,
    ): string {
        if ($estricto && ! preg_match('/^[\x20-\x7E]+$/D', $valor)) {
            throw new \DomainException('El folio contiene caracteres que Code 128 no puede representar.');
        }
        $numerico = $estricto && ctype_digit($valor) && strlen($valor) % 2 === 0;
        $inicio = $numerico ? 105 : 104;
        $codigos = [$inicio];
        foreach (str_split($valor, $numerico ? 2 : 1) as $caracter) {
            if ($numerico) {
                $codigos[] = (int) $caracter;

                continue;
            }
            $ascii = ord($caracter);
            if ($ascii < 32 || $ascii > 126) {
                continue;
            }
            $codigos[] = $ascii - 32;
        }
        $checksum = $inicio;
        foreach (array_slice($codigos, 1) as $indice => $codigo) {
            $checksum += $codigo * ($indice + 1);
        }
        $codigos[] = $checksum % 103;
        $codigos[] = 106;
        $unidades = array_sum(array_map(
            fn (int $codigo): int => array_sum(array_map('intval', str_split(self::CODE128[$codigo]))),
            $codigos,
        )) + ($estricto ? 20 : 2);
        $modulo = $ancho / $unidades;
        if ($estricto && $modulo < (0.19 / 25.4) * 72) {
            throw new \DomainException('El folio es demasiado largo para un código legible en este tamaño de etiqueta.');
        }
        $cursor = $x + ($estricto ? 10 * $modulo : 0);
        $contenido = '0 0 0 rg ';

        foreach ($codigos as $codigo) {
            $barra = true;
            foreach (str_split(self::CODE128[$codigo]) as $segmento) {
                $segmentoAncho = ((int) $segmento) * $modulo;
                if ($barra) {
                    $contenido .= sprintf('%.3F %.3F %.3F %.3F re f ', $cursor, $y, $segmentoAncho, $alto);
                }
                $cursor += $segmentoAncho;
                $barra = ! $barra;
            }
        }

        return $contenido."\n";
    }

    /**
     * @param  array<string, mixed>  $etiqueta
     * @return array<int, string>
     */
    private function lineasOrigen(array $etiqueta): array
    {
        if (($etiqueta['origen'] ?? 'recepcion') === 'transformacion') {
            return [
                'Transformación: '.$etiqueta['orden_transformacion']
                    .' · Lote '.$etiqueta['numero_lote_transformacion'],
                'Lote material: '.($etiqueta['lote_proveedor'] ?: '—'),
            ];
        }

        return [
            'Guía: '.$etiqueta['numero_guia'].' · Lote: '.($etiqueta['lote_proveedor'] ?: '—'),
            'Proveedor: '.$etiqueta['proveedor_nombre'],
            'Fecha recepción: '.(($etiqueta['fecha_recepcion'] ?? null) ?: '—'),
        ];
    }

    private function tamanoDetalle(
        string $texto,
        float $ancho,
        float $maximo,
        bool $negrita,
    ): float {
        $caracteres = max(1, mb_strlen($texto));
        $factor = $negrita ? 0.62 : 0.55;
        $calculado = $ancho / ($caracteres * $factor);

        return max(4, min($maximo, floor($calculado)));
    }

    protected function texto(
        float $x,
        float $y,
        float $tamano,
        string $texto,
        bool $negrita = false,
        string $color = '0 0 0',
    ): string {
        $texto = function_exists('iconv')
            ? (iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto) ?: $texto)
            : $texto;
        $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $texto);

        return sprintf(
            "%s rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $color,
            $negrita ? 'F2' : 'F1',
            $tamano,
            $x,
            $y,
            $texto,
        );
    }

    /** @param array<int, string> $paginas */
    private function documento(array $paginas, float $ancho, float $alto): string
    {
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $referenciasPaginas = [];

        foreach ($paginas as $indice => $contenido) {
            $paginaRef = 5 + ($indice * 2);
            $contenidoRef = $paginaRef + 1;
            $referenciasPaginas[] = "{$paginaRef} 0 R";
            $objetos[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $ancho,
                $alto,
                $contenidoRef,
            );
            $objetos[] = '<< /Length '.strlen($contenido).">>\nstream\n{$contenido}endstream";
        }
        $objetos[1] = '<< /Type /Pages /Kids ['.implode(' ', $referenciasPaginas).'] /Count '.count($paginas).' >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($pdf);
            $numero = $indice + 1;
            $pdf .= "{$numero} 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function puntos(float $milimetros): float
    {
        return ($milimetros / 25.4) * 72;
    }

    /** @param array<string, mixed> $perfil */
    private function dimensiones(array $perfil): array
    {
        $ancho = (float) $perfil['ancho_mm'];
        $alto = (float) $perfil['alto_mm'];

        return ($perfil['orientacion'] ?? 'horizontal') === 'vertical'
            ? [min($ancho, $alto), max($ancho, $alto)]
            : [max($ancho, $alto), min($ancho, $alto)];
    }
}
