<?php

namespace App\Services\Materiales;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GeneradorMiniaturaItemMaterial
{
    /** @return array{jpeg:string,ancho:int,alto:int} */
    public function generar(UploadedFile $archivo): array
    {
        $dimensiones = @getimagesize($archivo->getRealPath());
        if (! $dimensiones || $dimensiones[0] * $dimensiones[1] > 25000000) {
            throw ValidationException::withMessages(['fotografias' => 'La fotografía no es legible o supera los 25 megapíxeles.']);
        }
        $this->validarMemoria($archivo, $dimensiones[0] * $dimensiones[1]);
        $orientacion = $archivo->getMimeType() === 'image/jpeg' ? $this->orientacion($archivo->getRealPath()) : 1;
        $imagen = @imagecreatefromstring(file_get_contents($archivo->getRealPath()));
        if (! $imagen) {
            throw ValidationException::withMessages(['fotografias' => 'No se pudo leer la fotografía.']);
        }
        $miniatura = null;
        try {
            $anchoOriginal = imagesx($imagen);
            $altoOriginal = imagesy($imagen);
            $escala = min(1, 300 / max($anchoOriginal, $altoOriginal));
            $miniatura = imagecreatetruecolor(max(1, (int) round($anchoOriginal * $escala)), max(1, (int) round($altoOriginal * $escala)));
            imagefill($miniatura, 0, 0, imagecolorallocate($miniatura, 255, 255, 255));
            imagecopyresampled($miniatura, $imagen, 0, 0, 0, 0, imagesx($miniatura), imagesy($miniatura), $anchoOriginal, $altoOriginal);
            // Liberar el original antes de orientar: nunca duplicar la imagen a resolución completa.
            imagedestroy($imagen);
            $imagen = null;
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
                if (! $rotada) {
                    throw new RuntimeException('No se pudo orientar la miniatura.');
                }
                imagedestroy($miniatura);
                $miniatura = $rotada;
            }
            [$ancho, $alto] = in_array($orientacion, [5, 6, 7, 8], true) ? [$altoOriginal, $anchoOriginal] : [$anchoOriginal, $altoOriginal];
            ob_start();
            try {
                if (! imagejpeg($miniatura, null, 85)) {
                    throw new RuntimeException('No se pudo generar la miniatura.');
                }
                $jpeg = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            return compact('jpeg', 'ancho', 'alto');
        } finally {
            if ($miniatura) {
                imagedestroy($miniatura);
            }
            if ($imagen) {
                imagedestroy($imagen);
            }
        }
    }

    private function validarMemoria(UploadedFile $archivo, int $pixeles): void
    {
        $limite = ini_parse_quantity((string) ini_get('memory_limit'));
        // Presupuesto conservador: un raster GD, buffers del archivo y 16 MiB
        // para las estructuras del decodificador y las miniaturas temporales.
        $necesaria = $pixeles * 6 + $archivo->getSize() * 2 + 16 * 1024 * 1024;
        if ($limite > 0 && $necesaria > $limite - memory_get_usage(true)) {
            throw ValidationException::withMessages(['fotografias' => 'La resolución de la fotografía supera la memoria disponible del servidor. Reduce su tamaño e inténtalo nuevamente.']);
        }
    }

    private function orientacion(string $ruta): int
    {
        if (! function_exists('exif_read_data')) {
            Log::warning('EXIF no está disponible: la miniatura del ítem se genera con orientación normal.', ['extension' => 'exif']);

            return 1;
        }

        $orientacion = (int) (@exif_read_data($ruta)['Orientation'] ?? 1);

        return in_array($orientacion, range(1, 8), true) ? $orientacion : 1;
    }
}
