<?php

namespace App\Services\ControlDespacho;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Las fotos del despacho no se guardan como llegan del equipo. Se dejan derechas (EXIF), en
 * JPEG de hasta 1600 px por lado y con una miniatura para la pantalla. Así una foto de varios
 * megabytes ocupa unos cientos de kilobytes, y es esa versión la que después va al PDF.
 */
class CompresorFotoControlDespacho
{
    public const LADO_MAXIMO = 1600;

    public const CALIDAD = 72;

    public const LADO_MINIATURA = 300;

    /** @return array{jpeg: string, ancho: int, alto: int, miniatura: string} */
    public function comprimir(UploadedFile $archivo): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Las fotos del despacho requieren la extensión GD de PHP.');
        }
        $ruta = $archivo->getRealPath();
        $dimensiones = @getimagesize($ruta);
        if (! $dimensiones || $dimensiones[0] * $dimensiones[1] > 25_000_000) {
            throw ValidationException::withMessages(['archivo' => 'La foto no es legible o supera los 25 megapíxeles.']);
        }
        $this->validarMemoria($dimensiones[0] * $dimensiones[1], (int) $archivo->getSize());
        $original = @imagecreatefromstring((string) file_get_contents($ruta));
        if (! $original instanceof GdImage) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer la foto.']);
        }
        $orientacion = $archivo->getMimeType() === 'image/jpeg' ? $this->orientacion($ruta) : 1;
        $grande = null;
        $miniatura = null;
        try {
            $grande = $this->orientar($this->escalar($original, self::LADO_MAXIMO), $orientacion);
            imagedestroy($original);
            $original = null;
            $miniatura = $this->escalar($grande, self::LADO_MINIATURA);

            return [
                'jpeg' => $this->jpeg($grande, self::CALIDAD),
                'ancho' => imagesx($grande),
                'alto' => imagesy($grande),
                'miniatura' => $this->jpeg($miniatura, 80),
            ];
        } finally {
            foreach ([$original, $grande, $miniatura] as $imagen) {
                if ($imagen instanceof GdImage) {
                    imagedestroy($imagen);
                }
            }
        }
    }

    /** Copia reducida sobre fondo blanco (las PNG con transparencia no quedan negras). */
    private function escalar(GdImage $imagen, int $lado): GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $escala = min(1, $lado / max($ancho, $alto));
        $copia = imagecreatetruecolor(max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala)));
        imagefill($copia, 0, 0, imagecolorallocate($copia, 255, 255, 255));
        imagecopyresampled($copia, $imagen, 0, 0, 0, 0, imagesx($copia), imagesy($copia), $ancho, $alto);

        return $copia;
    }

    private function orientar(GdImage $imagen, int $orientacion): GdImage
    {
        if (in_array($orientacion, [2, 5, 7], true)) {
            imageflip($imagen, IMG_FLIP_HORIZONTAL);
        }
        if ($orientacion === 4) {
            imageflip($imagen, IMG_FLIP_VERTICAL);
        }
        $angulo = match ($orientacion) {
            3 => 180, 6, 7 => -90, 5, 8 => 90, default => 0,
        };
        if ($angulo === 0) {
            return $imagen;
        }
        $rotada = imagerotate($imagen, $angulo, 0);
        if (! $rotada instanceof GdImage) {
            throw new RuntimeException('No se pudo orientar la foto.');
        }
        imagedestroy($imagen);

        return $rotada;
    }

    private function jpeg(GdImage $imagen, int $calidad): string
    {
        ob_start();
        try {
            if (! imagejpeg($imagen, null, $calidad)) {
                throw new RuntimeException('No se pudo comprimir la foto.');
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function orientacion(string $ruta): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $orientacion = (int) (@exif_read_data($ruta)['Orientation'] ?? 1);

        return in_array($orientacion, range(1, 8), true) ? $orientacion : 1;
    }

    private function validarMemoria(int $pixeles, int $bytes): void
    {
        $limite = ini_parse_quantity((string) ini_get('memory_limit'));
        $necesaria = $pixeles * 6 + $bytes * 2 + 32 * 1024 * 1024;
        if ($limite > 0 && memory_get_usage(true) + $necesaria > $limite) {
            throw ValidationException::withMessages(['archivo' => 'La foto es demasiado grande para procesarla. Tómala con menor resolución.']);
        }
    }
}
