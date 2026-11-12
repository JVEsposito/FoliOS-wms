<?php

namespace App\Services\ControlDespacho;

/**
 * PDF «Set fotográfico R-24»: tres fotos por página A4, con su categoría y la hora en que se
 * tomaron (un set de 40 fotos queda en 14 páginas). Las fotos ya vienen en JPEG y se incrustan tal cual (DCTDecode), sin recomprimir.
 *
 * Devuelve dónde quedó el JPEG de cada foto dentro del PDF: si la planilla se reabre, el PDF se
 * rearma leyendo esas fotos desde el PDF anterior, porque sus archivos sueltos ya no existen.
 */
class GeneradorPdfFotosControlDespacho
{
    private const ANCHO_PAGINA = 595;

    private const ALTO_PAGINA = 842;

    private const FOTOS_POR_PAGINA = 3;

    /** Alto de cada franja de foto: rótulo, imagen y separación. */
    private const FRANJA = 240;

    /**
     * @param  array{titulo: string, detalle: string, pie: string}  $encabezado
     * @param  array<int, array{id: string, jpeg: string, ancho: int, alto: int, titulo: string, detalle: string}>  $fotos
     * @return array{pdf: string, ubicaciones: array<string, array{pagina: int, offset: int, longitud: int}>}
     */
    public function generar(array $encabezado, array $fotos): array
    {
        $paginas = [];
        $grupos = array_chunk($fotos, self::FOTOS_POR_PAGINA);
        foreach ($grupos as $indice => $grupo) {
            $contenido = $this->texto(40, 800, 15, $encabezado['titulo'], true);
            $contenido .= $this->texto(40, 782, 9, $encabezado['detalle']);
            $contenido .= $this->texto(40, 30, 8, $encabezado['pie'].' · Página '.($indice + 1).' de '.count($grupos));
            $imagenes = [];
            foreach ($grupo as $posicion => $foto) {
                // Cada foto ocupa un tercio de la página útil, centrada y sin deformarse.
                $techo = 760 - $posicion * self::FRANJA;
                $maximoAncho = 515;
                $maximoAlto = self::FRANJA - 34;
                $escala = min($maximoAncho / $foto['ancho'], $maximoAlto / $foto['alto']);
                $ancho = $foto['ancho'] * $escala;
                $alto = $foto['alto'] * $escala;
                $x = 40 + ($maximoAncho - $ancho) / 2;
                $y = $techo - 22 - $alto;
                $nombre = 'Im'.$posicion;
                $contenido .= $this->texto(40, $techo, 11, $foto['titulo'], true);
                $contenido .= $this->texto(40, $techo - 13, 8, $foto['detalle']);
                $contenido .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $ancho, $alto, $x, $y, $nombre);
                $imagenes[$nombre] = $foto;
            }
            $paginas[] = ['contenido' => $contenido, 'imagenes' => $imagenes];
        }

        return $this->documento($paginas);
    }

    /**
     * @param  array<int, array{contenido: string, imagenes: array<string, array{id: string, jpeg: string, ancho: int, alto: int}>}>  $paginas
     * @return array{pdf: string, ubicaciones: array<string, array{pagina: int, offset: int, longitud: int}>}
     */
    private function documento(array $paginas): array
    {
        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $imagenPorObjeto = [];
        $hijos = [];
        $siguiente = 5;
        foreach ($paginas as $indice => $pagina) {
            $numeroPagina = $siguiente++;
            $numeroContenido = $siguiente++;
            $hijos[] = "{$numeroPagina} 0 R";
            $referencias = [];
            foreach ($pagina['imagenes'] as $nombre => $imagen) {
                $numero = $siguiente++;
                $referencias[] = "/{$nombre} {$numero} 0 R";
                $objetos[$numero] = "<< /Type /XObject /Subtype /Image /Width {$imagen['ancho']} /Height {$imagen['alto']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($imagen['jpeg'])." >>\nstream\n{$imagen['jpeg']}\nendstream";
                $imagenPorObjeto[$numero] = ['id' => $imagen['id'], 'pagina' => $indice + 1, 'longitud' => strlen($imagen['jpeg'])];
            }
            $recursos = '<< /Font << /F1 3 0 R /F2 4 0 R >> /XObject << '.implode(' ', $referencias).' >> >>';
            $objetos[$numeroPagina] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::ANCHO_PAGINA.' '.self::ALTO_PAGINA."] /Resources {$recursos} /Contents {$numeroContenido} 0 R >>";
            $objetos[$numeroContenido] = '<< /Length '.strlen($pagina['contenido'])." >>\nstream\n{$pagina['contenido']}endstream";
        }
        $objetos[2] = '<< /Type /Pages /Kids ['.implode(' ', $hijos).'] /Count '.count($hijos).' >>';
        ksort($objetos);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        $ubicaciones = [];
        foreach ($objetos as $numero => $objeto) {
            $offsets[$numero] = strlen($pdf);
            $inicio = "{$numero} 0 obj\n";
            if (isset($imagenPorObjeto[$numero])) {
                $datos = $imagenPorObjeto[$numero];
                $ubicaciones[$datos['id']] = [
                    'pagina' => $datos['pagina'],
                    'offset' => strlen($pdf) + strlen($inicio) + strpos($objeto, "stream\n") + strlen("stream\n"),
                    'longitud' => $datos['longitud'],
                ];
            }
            $pdf .= "{$inicio}{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $cantidad = max(array_keys($objetos)) + 1;
        $pdf .= "xref\n0 {$cantidad}\n0000000000 65535 f \n";
        for ($numero = 1; $numero < $cantidad; $numero++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$numero])."\n";
        }

        return [
            'pdf' => $pdf."trailer\n<< /Size {$cantidad} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF",
            'ubicaciones' => $ubicaciones,
        ];
    }

    private function texto(float $x, float $y, int $tamano, string $texto, bool $negrita = false): string
    {
        $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto) ?: '';
        $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $texto);

        return sprintf("BT /%s %d Tf 0.10 0.18 0.23 rg %.2F %.2F Td (%s) Tj ET\n", $negrita ? 'F2' : 'F1', $tamano, $x, $y, $texto);
    }
}
