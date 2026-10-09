<?php

namespace App\Services\Materiales {
    // Asegura que incluso una fotografía grande se rota solo después de reducir.
    function imagerotate(\GdImage $imagen, float $angulo, int $fondo): \GdImage|false
    {
        if (max(imagesx($imagen), imagesy($imagen)) > 300) {
            throw new \RuntimeException('Se intentó rotar una imagen a resolución completa.');
        }

        return \imagerotate($imagen, $angulo, $fondo);
    }
}

namespace {
    use App\Services\Materiales\GeneradorMiniaturaItemMaterial;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Http\UploadedFile;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Validation\ValidationException;
    use Psr\Log\AbstractLogger;

    if (($argv[1] ?? '') === '--crear') {
        [$ancho, $alto, $orientacion] = array_map('intval', array_slice($argv, 2, 3));
        $ruta = $argv[5];
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 20, 20, 220));
        imagefilledrectangle($imagen, 0, 0, (int) ($ancho / 2) - 1, (int) ($alto / 2) - 1, imagecolorallocate($imagen, 240, 20, 20));
        imagejpeg($imagen, $ruta, 95);
        imagedestroy($imagen);
        $imagen = null;
        $jpeg = file_get_contents($ruta);
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVvvV', 0x0112, 3, 1, $orientacion, 0, 0);
        file_put_contents($ruta, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        exit;
    }

    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $log = new class extends AbstractLogger
    {
        public array $mensajes = [];

        public function log($level, $message, array $context = []): void
        {
            $this->mensajes[] = ['nivel' => $level, 'mensaje' => (string) $message, 'contexto' => $context];
        }
    };
    Log::swap($log);
    try {
        $archivo = new UploadedFile($argv[1], 'foto.jpg', 'image/jpeg', null, true);
        $datos = app(GeneradorMiniaturaItemMaterial::class)->generar($archivo);
        $miniatura = imagecreatefromstring($datos['jpeg']);
        echo json_encode(['original' => [$datos['ancho'], $datos['alto']], 'miniatura' => [imagesx($miniatura), imagesy($miniatura)], 'avisos' => $log->mensajes]);
        imagedestroy($miniatura);
    } catch (ValidationException $error) {
        echo json_encode(['errores' => $error->errors()]);
    }
}
