<?php

namespace App\Services\Materiales;

class GeneradorEtiquetaMaterialZpl
{
    /**
     * @param  array<int, array<string, mixed>>  $etiquetas
     * @param  array<string, mixed>  $perfil
     */
    public function generar(
        array $etiquetas,
        array $perfil,
        int $copias = 1,
        string $simbologia = 'code128',
    ): string {
        $dpi = (int) $perfil['dpi'];
        [$anchoMm, $altoMm] = $this->dimensiones($perfil);
        $ancho = $this->puntos($anchoMm, $dpi);
        $alto = $this->puntos($altoMm, $dpi);
        $margen = max(12, (int) round($ancho * 0.035));
        $contenido = '';

        foreach ($etiquetas as $etiqueta) {
            for ($copia = 0; $copia < $copias; $copia++) {
                $contenido .= "^XA\n";
                $contenido .= "^CI28\n";
                $contenido .= "^PW{$ancho}\n^LL{$alto}\n^LH0,0\n";
                $contenido .= "^FO0,0^GB{$ancho},".max(35, (int) round($alto * 0.17)).",35^FS\n";
                $contenido .= $this->texto(
                    $margen,
                    max(5, (int) round($alto * 0.035)),
                    max(24, (int) round($alto * 0.095)),
                    max(18, (int) round($alto * 0.075)),
                    (string) $etiqueta['numero_folio'],
                    inverso: true,
                );

                $barcodeY = max(48, (int) round($alto * 0.20));
                $barcodeHeight = max(38, (int) round($alto * 0.24));
                if ($simbologia === 'qr') {
                    $magnificacion = max(2, min(10, (int) floor($barcodeHeight / 25)));
                    $contenido .= sprintf(
                        "^FO%d,%d^BQN,2,%d^FH\\^FDLA,%s^FS\n",
                        $margen,
                        $barcodeY,
                        $magnificacion,
                        $this->campo((string) $etiqueta['numero_folio']),
                    );
                    $contenido .= $this->texto(
                        $margen,
                        $barcodeY + ($magnificacion * 23) + 8,
                        max(17, (int) round($alto * 0.05)),
                        max(14, (int) round($alto * 0.04)),
                        (string) $etiqueta['numero_folio'],
                    );
                    $lineY = $barcodeY + ($magnificacion * 23)
                        + max(40, (int) round($alto * 0.12));
                } else {
                    $moduleWidth = $dpi >= 300 ? 3 : 2;
                    $contenido .= sprintf(
                        "^BY%d,2,%d^FO%d,%d^BCN,%d,Y,N,N^FH\\^FD%s^FS\n",
                        $moduleWidth,
                        $barcodeHeight,
                        $margen,
                        $barcodeY,
                        $barcodeHeight,
                        $this->campo((string) $etiqueta['numero_folio']),
                    );
                    $lineY = $barcodeY + $barcodeHeight
                        + max(38, (int) round($alto * 0.10));
                }
                $fontHeight = max(17, (int) round($alto * 0.055));
                $lineHeightBase = max(22, (int) round($alto * 0.072));
                $lineas = [
                    $etiqueta['cliente_codigo'].' · '.$etiqueta['cliente_nombre'],
                    $etiqueta['item_codigo'].' · '.$etiqueta['item_nombre'],
                    'Cantidad: '.$etiqueta['cantidad'].' '.$etiqueta['unidad_medida'],
                    ...$this->lineasOrigen($etiqueta),
                ];
                if ($etiqueta['bloqueado']) {
                    $lineas[] = 'BLOQUEADO: '.($etiqueta['motivo_bloqueo'] ?: 'Sin motivo');
                }

                $lineHeight = $lineHeightBase;
                if (count($lineas) > 1) {
                    $altoDisponible = max(1, $alto - 5 - $lineY - $fontHeight);
                    $lineHeight = min(
                        $lineHeightBase,
                        max($fontHeight, (int) floor($altoDisponible / (count($lineas) - 1))),
                    );
                }

                foreach ($lineas as $indice => $linea) {
                    $y = $lineY + ($indice * $lineHeight);
                    if ($y + $fontHeight >= $alto - 5) {
                        break;
                    }
                    $texto = (string) $linea;
                    [$altoTexto, $anchoTexto] = $this->tamanoDetalle(
                        $texto,
                        $ancho - ($margen * 2),
                        $fontHeight,
                    );
                    $contenido .= $this->texto(
                        $margen,
                        $y,
                        $altoTexto,
                        $anchoTexto,
                        $texto,
                    );
                }

                $contenido .= "^PQ1,0,1,N\n^XZ\n";
            }
        }

        return $contenido;
    }

    private function puntos(float $milimetros, int $dpi): int
    {
        return max(1, (int) round(($milimetros / 25.4) * $dpi));
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

    /**
     * @param  array<string, mixed>  $etiqueta
     * @return array<int, string>
     */
    private function lineasOrigen(array $etiqueta): array
    {
        if (($etiqueta['origen'] ?? 'recepcion') === 'transferencia_cliente') {
            return ['Transferencia desde: '.$etiqueta['folio_origen'],
                'Proveedor: '.($etiqueta['proveedor_nombre'] ?: '—').' · Lote: '.($etiqueta['lote_proveedor'] ?: '—'),
                'Ingreso original: '.($etiqueta['fecha_recepcion'] ?: '—')];
        }
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

    /** @return array{int, int} */
    private function tamanoDetalle(
        string $texto,
        int $ancho,
        int $altoMaximo,
    ): array {
        $caracteres = max(1, mb_strlen($texto));
        $anchoBase = max(14, (int) round($altoMaximo * 0.82));
        $anchoCalculado = (int) floor($ancho / ($caracteres * 0.72));
        $anchoFuente = max(8, min($anchoBase, $anchoCalculado));
        $altoFuente = max(
            10,
            min($altoMaximo, (int) round($anchoFuente / 0.82)),
        );

        return [$altoFuente, $anchoFuente];
    }

    private function texto(
        int $x,
        int $y,
        int $alto,
        int $ancho,
        string $texto,
        bool $inverso = false,
    ): string {
        return sprintf(
            "^FO%d,%d^A0N,%d,%d%s^FH\\^FD%s^FS\n",
            $x,
            $y,
            $alto,
            $ancho,
            $inverso ? '^FR' : '',
            $this->campo($texto),
        );
    }

    private function campo(string $valor): string
    {
        return str_replace(
            ['\\', '^', '~', "\r", "\n"],
            ['\\5C', '\\5E', '\\7E', ' ', ' '],
            $valor,
        );
    }
}
