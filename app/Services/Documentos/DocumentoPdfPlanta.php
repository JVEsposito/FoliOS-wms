<?php

namespace App\Services\Documentos;

class DocumentoPdfPlanta
{
    public function texto(float $x, float $y, float $tamano, string $texto, bool $negrita = false): string
    {
        $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto) ?: $texto;
        $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $texto);
        $fuente = $negrita ? 'F2' : 'F1';

        return sprintf("0 g BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $fuente, $tamano, $x, $y, $texto);
    }

    public function envolver(string $texto, float $ancho, float $tamano): array
    {
        $lineas = [];
        foreach (preg_split('/\R/u', $texto) ?: [''] as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) ?: [''] as $palabra) {
                if ($linea !== '' && $this->anchoTexto($linea.' '.$palabra, $tamano) > $ancho) {
                    $lineas[] = $linea;
                    $linea = '';
                }
                // Divide también un folio o una observación sin espacios.
                foreach (preg_split('//u', $palabra, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
                    if ($this->anchoTexto($linea.$caracter, $tamano) > $ancho) {
                        $lineas[] = $linea;
                        $linea = '';
                    }
                    $linea .= $caracter;
                }
                $linea .= ' ';
            }
            $lineas[] = trim($linea);
        }

        return $lineas;
    }

    private function anchoTexto(string $texto, float $tamano): float
    {
        $ancho = 0;
        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            // Anchos conservadores para no recortar texto en Helvetica.
            $ancho += $tamano * (match (true) {
                str_contains('@%', $caracter) => 1.05,
                str_contains('MWmw', $caracter) => 0.95,
                str_contains(' ilI.,:;', $caracter) => 0.3,
                preg_match('/\p{Lu}/u', $caracter) => 0.78,
                default => 0.62,
            });
        }

        return $ancho;
    }

    /** @param array<int, string> $paginas */
    public function generar(array $paginas, array $tamanoPagina = [595, 842]): string
    {
        $logo = resource_path('images/logo-agrorosario.jpg');
        $imagen = file_get_contents($logo);
        [$ancho, $alto] = getimagesize($logo);
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($indice): string => (6 + $indice * 2).' 0 R', array_keys($paginas))).'] /Count '.count($paginas).' >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Type /XObject /Subtype /Image /Width '.$ancho.' /Height '.$alto.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($imagen)." >>\nstream\n{$imagen}\nendstream",
        ];
        foreach ($paginas as $indice => $contenido) {
            $objetos[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.implode(' ', $tamanoPagina).'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents '.(7 + $indice * 2).' 0 R >>';
            $objetos[] = '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}endstream";
        }
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
}
