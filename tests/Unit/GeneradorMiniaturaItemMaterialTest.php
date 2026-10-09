<?php

namespace Tests\Unit;

use App\Services\Materiales\GeneradorMiniaturaItemMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
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
        $archivo = $this->fotografia(600, 400, $orientacion);
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

    private function fotografia(int $ancho, int $alto, int $orientacion): UploadedFile
    {
        if ($ancho * $alto > 1000000) {
            $ruta = tempnam(sys_get_temp_dir(), 'item-foto-grande-');
            try {
                // La creación de la muestra no debe consumir el presupuesto del PHPUnit principal.
                $proceso = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Fixtures/miniatura-item-proceso.php'), '--crear', (string) $ancho, (string) $alto, (string) $orientacion, $ruta]);
                $proceso->mustRun();

                return UploadedFile::fake()->createWithContent('orientada.jpg', file_get_contents($ruta));
            } finally {
                unlink($ruta);
            }
        }
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 20, 20, 220));
        imagefilledrectangle($imagen, 0, 0, (int) ($ancho / 2) - 1, (int) ($alto / 2) - 1, imagecolorallocate($imagen, 240, 20, 20));
        ob_start();
        imagejpeg($imagen, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($imagen);
        // APP1 EXIF: TIFF little endian con una entrada SHORT para Orientation (0x0112).
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVvvV', 0x0112, 3, 1, $orientacion, 0, 0);
        $contenido = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        return UploadedFile::fake()->createWithContent('orientada.jpg', $contenido);
    }

    public function test_jpeg_sin_extension_exif_se_acepta_y_registra_una_advertencia(): void
    {
        $archivo = $this->fotografia(600, 400, 6);
        $resultado = $this->proceso($archivo, ['-d', 'disable_functions=exif_read_data']);
        $this->assertSame([600, 400], $resultado['original']);
        $this->assertSame([300, 200], $resultado['miniatura']);
        $this->assertCount(1, $resultado['avisos']);
        $this->assertSame('warning', $resultado['avisos'][0]['nivel']);
        $this->assertSame(['extension' => 'exif'], $resultado['avisos'][0]['contexto']);
    }

    public function test_foto_de_doce_mp_girada_se_reduce_antes_de_rotar_y_respeta_el_presupuesto_de_memoria(): void
    {
        $archivo = $this->fotografia(5000, 2400, 6);
        $resultado = $this->proceso($archivo, ['-d', 'memory_limit=128M']);
        $this->assertSame([2400, 5000], $resultado['original']);
        $this->assertSame([144, 300], $resultado['miniatura']);
        $this->assertSame([], $resultado['avisos']);
        $rechazo = $this->proceso($archivo, ['-d', 'memory_limit=64M']);
        $this->assertStringContainsString('memoria disponible', $rechazo['errores']['fotografias'][0]);
    }

    public function test_imagen_de_mas_de_veinticinco_mp_se_rechaza_antes_de_decodificar(): void
    {
        $archivo = $this->fotografia(600, 400, 1);
        $jpeg = file_get_contents($archivo->getRealPath());
        // Cambiar solo las dimensiones SOF: evita reservar un raster enorme para probar el límite.
        $sof = strpos($jpeg, "\xFF\xC0");
        $this->assertNotFalse($sof);
        file_put_contents($archivo->getRealPath(), substr_replace($jpeg, pack('nn', 5000, 6000), $sof + 5, 4));
        try {
            app(GeneradorMiniaturaItemMaterial::class)->generar($archivo);
            $this->fail('Debe rechazarse antes de decodificar.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('25 megapíxeles', $error->errors()['fotografias'][0]);
        }
    }

    private function proceso(UploadedFile $archivo, array $opciones): array
    {
        $proceso = new Process([PHP_BINARY, ...$opciones, base_path('tests/Fixtures/miniatura-item-proceso.php'), $archivo->getRealPath()], base_path());
        $proceso->mustRun();

        return json_decode($proceso->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
