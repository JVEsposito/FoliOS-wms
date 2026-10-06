<?php

namespace App\Services\Documentos;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use DomainException;

class CodigoQrPdf
{
    public function generar(string $valor, float $x, float $y, float $lado): string
    {
        if ($valor === '') {
            throw new DomainException('El QR necesita un folio.');
        }
        if (! preg_match('/^[\x20-\x7E]+$/D', $valor)) {
            throw new DomainException('El folio contiene caracteres que la etiqueta PT no puede representar.');
        }

        $matriz = Encoder::encode($valor, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        $modulos = $matriz->getWidth();
        $margen = 4;
        $modulo = $lado / ($modulos + 2 * $margen);
        // Al menos tres puntos por módulo en una impresora térmica de 203 dpi.
        if ($modulo < (0.4 / 25.4) * 72) {
            throw new DomainException('El folio es demasiado largo para un QR legible en este tamaño de etiqueta.');
        }

        $contenido = sprintf("%% QR folio\nq\n1 1 1 rg %.3F %.3F %.3F %.3F re f\n0 0 0 rg\n", $x, $y, $lado, $lado);
        for ($fila = 0; $fila < $modulos; $fila++) {
            for ($columna = 0; $columna < $modulos; $columna++) {
                if ($matriz->get($columna, $fila) === 1) {
                    $contenido .= sprintf(
                        "%.3F %.3F %.3F %.3F re f\n",
                        $x + ($columna + $margen) * $modulo,
                        $y + ($modulos - $fila - 1 + $margen) * $modulo,
                        $modulo,
                        $modulo,
                    );
                }
            }
        }

        return $contenido."Q\n";
    }
}
