<?php

namespace App\Services\MateriaPrima;

use App\Models\DefectoRecepcionMp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RegistroDefectosRecepcionPdf
{
    /** @param Collection<int, DefectoRecepcionMp> $defectos */
    public function generar(Collection $defectos, string $temporada): string
    {
        $paginas = [];
        foreach ($defectos as $indice => $defecto) {
            $contenido = $this->texto(40, 790, 17, 'DEFECTOS DE RECEPCIÓN', true);
            $contenido .= $this->texto(40, 765, 10, "Temporada: {$temporada} · Registro ".($indice + 1).' de '.$defectos->count());
            $campos = [
                'Fecha' => $defecto->registrado_at?->setTimezone((string) config('app.operational_timezone'))->format('d-m-Y H:i') ?? '—',
                'Recepción' => $defecto->numero_recepcion_snapshot ?: '—',
                'Guía de despacho' => $defecto->numero_guia_snapshot ?: '—',
                'Cliente' => $defecto->cliente_nombre_snapshot ?: '—',
                'Categoría' => str_replace('_', ' ', $defecto->categoria),
                'Envase' => $defecto->tipo_envase ?: '—',
                'Cantidad afectada' => (string) ($defecto->cantidad_afectada ?? '—'),
                'Validador' => $defecto->validador?->name ?: '—',
                'Tablet' => $defecto->dispositivo?->codigo ?: '—',
                'Identificador' => $defecto->id,
            ];
            $y = 735;
            foreach ($campos as $etiqueta => $valor) {
                $contenido .= $this->texto(40, $y, 10, $etiqueta.':', true);
                $contenido .= $this->texto(170, $y, 10, $valor);
                $y -= 22;
            }
            $contenido .= $this->texto(40, $y - 10, 11, 'Descripción del defecto', true);
            $y -= 30;
            $lineas = $this->lineas($defecto->descripcion, 88);
            foreach (array_splice($lineas, 0, 24) as $linea) {
                $contenido .= $this->texto(40, $y, 10, $linea);
                $y -= 15;
            }
            $contenido .= $this->pie();
            $paginas[] = ['contenido' => $contenido, 'imagenes' => []];

            foreach (array_chunk($lineas, 42) as $bloque) {
                $continuacion = $this->texto(40, 790, 15, 'DESCRIPCIÓN DEL DEFECTO · CONTINUACIÓN', true);
                $y = 760;
                foreach ($bloque as $linea) {
                    $continuacion .= $this->texto(40, $y, 10, $linea);
                    $y -= 15;
                }
                $paginas[] = ['contenido' => $continuacion.$this->pie(), 'imagenes' => []];
            }

            foreach ($defecto->evidencias->chunk(4) as $grupo) {
                $contenido = $this->texto(40, 790, 15, 'FOTOGRAFÍAS DEL DEFECTO', true);
                $contenido .= $this->texto(40, 765, 10, 'Recepción: '.$defecto->numero_recepcion_snapshot.' · Registro: '.$defecto->id);
                $imagenes = [];
                foreach ($grupo->values() as $posicion => $evidencia) {
                    $foto = $this->prepararImagen($evidencia->ruta);
                    $columna = $posicion % 2;
                    $fila = intdiv($posicion, 2);
                    $ancho = min(240, 280 * $foto['ancho'] / $foto['alto']);
                    $alto = $ancho * $foto['alto'] / $foto['ancho'];
                    $x = (int) (40 + $columna * 270 + (240 - $ancho) / 2);
                    $techo = 710 - $fila * 335;
                    $y = (int) ($techo - $alto);
                    $clave = 'Im'.$posicion;
                    $contenido .= $this->texto(40 + $columna * 270, $techo + 12, 10,
                        ($evidencia->tipo === 'guia' ? 'Guía' : 'Defecto').' · '.$evidencia->id);
                    $contenido .= sprintf("q %.2F 0 0 %.2F %d %d cm /%s Do Q\n", $ancho, $alto, $x, $y, $clave);
                    $imagenes[$clave] = $foto;
                }
                $paginas[] = ['contenido' => $contenido.$this->pie(), 'imagenes' => $imagenes];
            }
        }
        if ($paginas === []) {
            $paginas[] = ['contenido' => $this->texto(40, 790, 17, 'DEFECTOS DE RECEPCIÓN', true)
                .$this->texto(40, 760, 10, "Temporada: {$temporada}")
                .$this->texto(40, 735, 10, 'No hay registros que coincidan con los filtros.'), 'imagenes' => []];
        }

        return $this->documento($paginas);
    }

    /** @return array{datos:string,ancho:int,alto:int} */
    private function prepararImagen(string $ruta): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('La exportación PDF con fotos requiere habilitar la extensión GD de PHP.');
        }
        if (! Storage::disk('local')->exists($ruta)) {
            throw new RuntimeException('Falta una fotografía del registro de defectos.');
        }
        $original = @imagecreatefromstring(Storage::disk('local')->get($ruta));
        if ($original === false) {
            throw new RuntimeException('No se pudo leer una fotografía del registro de defectos.');
        }
        $ancho = imagesx($original);
        $alto = imagesy($original);
        $escala = min(1, 900 / max($ancho, $alto));
        $ancho = max(1, (int) round($ancho * $escala));
        $alto = max(1, (int) round($alto * $escala));
        $imagen = imagecreatetruecolor($ancho, $alto);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        imagefill($imagen, 0, 0, $blanco);
        imagecopyresampled($imagen, $original, 0, 0, 0, 0, $ancho, $alto, imagesx($original), imagesy($original));
        imagedestroy($original);
        ob_start();
        $generada = imagejpeg($imagen, null, 68);
        $datos = ob_get_clean();
        imagedestroy($imagen);
        if (! $generada || $datos === false) {
            throw new RuntimeException('No se pudo incorporar una fotografía al PDF.');
        }

        return ['datos' => $datos, 'ancho' => $ancho, 'alto' => $alto];
    }

    /** @return array<int, string> */
    private function lineas(string $texto, int $maximo): array
    {
        $lineas = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $parrafo) {
            $actual = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) ?: [] as $palabra) {
                foreach (mb_str_split($palabra, $maximo) as $parte) {
                    if (mb_strlen($actual.' '.$parte) > $maximo && $actual !== '') {
                        $lineas[] = $actual;
                        $actual = '';
                    }
                    $actual .= ($actual === '' ? '' : ' ').$parte;
                }
            }
            $lineas[] = $actual;
        }

        return $lineas;
    }

    private function pie(): string
    {
        return $this->texto(40, 35, 9, 'FoliOS · Registro de auditoría generado '.now()->format('d-m-Y H:i'));
    }

    private function texto(int $x, int $y, int $tamano, string $texto, bool $negrita = false): string
    {
        $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto) ?: '';
        $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $texto);
        $fuente = $negrita ? 'F2' : 'F1';

        return "BT /{$fuente} {$tamano} Tf 0.10 0.18 0.23 rg {$x} {$y} Td ({$texto}) Tj ET\n";
    }

    /** @param array<int, array{contenido:string,imagenes:array<string, array{datos:string,ancho:int,alto:int}>}> $paginas */
    private function documento(array $paginas): string
    {
        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $hijos = [];
        $siguiente = 5;
        foreach ($paginas as $pagina) {
            $numeroPagina = $siguiente++;
            $numeroContenido = $siguiente++;
            $hijos[] = "{$numeroPagina} 0 R";
            $referencias = [];
            foreach ($pagina['imagenes'] as $nombre => $imagen) {
                $numeroImagen = $siguiente++;
                $referencias[] = "/{$nombre} {$numeroImagen} 0 R";
                $datos = $imagen['datos'];
                $objetos[$numeroImagen] = "<< /Type /XObject /Subtype /Image /Width {$imagen['ancho']} /Height {$imagen['alto']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($datos)." >>\nstream\n{$datos}\nendstream";
            }
            $recursos = '<< /Font << /F1 3 0 R /F2 4 0 R >> /XObject << '.implode(' ', $referencias).' >> >>';
            $objetos[$numeroPagina] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources {$recursos} /Contents {$numeroContenido} 0 R >>";
            $contenido = $pagina['contenido'];
            $objetos[$numeroContenido] = '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}endstream";
        }
        $objetos[2] = '<< /Type /Pages /Kids ['.implode(' ', $hijos).'] /Count '.count($hijos).' >>';
        ksort($objetos);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objetos as $numero => $objeto) {
            $offsets[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $cantidad = max(array_keys($objetos)) + 1;
        $pdf .= "xref\n0 {$cantidad}\n0000000000 65535 f \n";
        for ($numero = 1; $numero < $cantidad; $numero++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$numero])."\n";
        }

        return $pdf."trailer\n<< /Size {$cantidad} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
