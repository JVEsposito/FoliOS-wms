<?php

namespace Tests\Unit;

use App\Services\Materiales\GeneradorMiniaturaItemMaterial;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeneradorMiniaturaItemMaterialTest extends TestCase
{
    public static function orientaciones(): array
    {
        // Posición del cuadrante rojo original (superior izquierdo) después de orientar.
        return [1 => [1, 0, 0], 2 => [2, 1, 0], 3 => [3, 1, 1], 4 => [4, 0, 1], 5 => [5, 0, 0], 6 => [6, 1, 0], 7 => [7, 1, 1], 8 => [8, 0, 1]];
    }

    #[DataProvider('orientaciones')]
    public function test_miniatura_jpeg_respeta_las_ocho_orientaciones_exif(int $orientacion, int $columna, int $fila): void
    {
        $imagen = imagecreatetruecolor(600, 400);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 20, 20, 220));
        imagefilledrectangle($imagen, 0, 0, 299, 199, imagecolorallocate($imagen, 240, 20, 20));
        ob_start();
        imagejpeg($imagen, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($imagen);
        // APP1 EXIF: TIFF little endian con una entrada SHORT para Orientation (0x0112).
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVvvV', 0x0112, 3, 1, $orientacion, 0, 0);
        $contenido = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $archivo = UploadedFile::fake()->createWithContent('orientada.jpg', $contenido);
        $datos = app(GeneradorMiniaturaItemMaterial::class)->generar($archivo);
        $this->assertSame($orientacion >= 5 ? [400, 600] : [600, 400], [$datos['ancho'], $datos['alto']]);
        $miniatura = imagecreatefromstring($datos['jpeg']);
        try {
            $ancho = imagesx($miniatura);
            $alto = imagesy($miniatura);
            $this->assertSame(300, max($ancho, $alto));
            $pixel = imagecolorat($miniatura, (int) ($ancho * ($columna ? .75 : .25)), (int) ($alto * ($fila ? .75 : .25)));
            $this->assertGreaterThan(200, ($pixel >> 16) & 255);
            $this->assertLessThan(50, $pixel & 255);
        } finally {
            imagedestroy($miniatura);
        }
    }
}
