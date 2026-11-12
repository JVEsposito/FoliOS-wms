<?php

namespace App\Services\Autorizacion;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProcesadorFotoPerfil
{
    /** JPEG pequeño, orientado y sin los metadatos del archivo original. */
    public function procesar(UploadedFile $archivo): string
    {
        abort_unless(extension_loaded('gd'), 503, 'El servidor no puede procesar fotos en este momento.');
        $ruta = $archivo->getRealPath();
        $dimensiones = @getimagesize($ruta);
        if (! $dimensiones || $dimensiones[0] * $dimensiones[1] > 25_000_000) {
            throw ValidationException::withMessages(['foto' => 'La foto no es legible o supera los 25 megapíxeles.']);
        }
        $limite = ini_parse_quantity((string) ini_get('memory_limit'));
        $necesaria = $dimensiones[0] * $dimensiones[1] * 6 + $archivo->getSize() * 2 + 16 * 1024 * 1024;
        if ($limite > 0 && memory_get_usage(true) + $necesaria > $limite) {
            throw ValidationException::withMessages(['foto' => 'La foto es demasiado grande para procesarla. Selecciona una de menor resolución.']);
        }
        $original = @imagecreatefromstring((string) file_get_contents($ruta));
        if (! $original instanceof GdImage) {
            throw ValidationException::withMessages(['foto' => 'No se pudo leer la foto. Selecciona otra imagen.']);
        }
        $miniatura = null;
        try {
            $escala = min(1, 384 / max($dimensiones[0], $dimensiones[1]));
            $miniatura = imagecreatetruecolor(max(1, (int) round($dimensiones[0] * $escala)), max(1, (int) round($dimensiones[1] * $escala)));
            imagefill($miniatura, 0, 0, imagecolorallocate($miniatura, 255, 255, 255));
            imagecopyresampled($miniatura, $original, 0, 0, 0, 0, imagesx($miniatura), imagesy($miniatura), $dimensiones[0], $dimensiones[1]);
            imagedestroy($original);
            $original = null;
            $orientacion = $archivo->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')
                ? (int) (@exif_read_data($ruta)['Orientation'] ?? 1) : 1;
            if (in_array($orientacion, [2, 5, 7], true)) {
                imageflip($miniatura, IMG_FLIP_HORIZONTAL);
            }
            if ($orientacion === 4) {
                imageflip($miniatura, IMG_FLIP_VERTICAL);
            }
            $angulo = match ($orientacion) {
                3 => 180, 6, 7 => -90, 5, 8 => 90, default => 0
            };
            if ($angulo !== 0) {
                $rotada = imagerotate($miniatura, $angulo, 0);
                if (! $rotada instanceof GdImage) {
                    throw new RuntimeException('No se pudo orientar la foto de perfil.');
                }
                imagedestroy($miniatura);
                $miniatura = $rotada;
            }
            ob_start();
            try {
                if (! imagejpeg($miniatura, null, 85)) {
                    throw new RuntimeException('No se pudo preparar la foto de perfil.');
                }

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            foreach ([$original, $miniatura] as $imagen) {
                if ($imagen instanceof GdImage) {
                    imagedestroy($imagen);
                }
            }
        }
    }
}
