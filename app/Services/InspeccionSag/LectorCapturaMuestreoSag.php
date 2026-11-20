<?php

namespace App\Services\InspeccionSag;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class LectorCapturaMuestreoSag
{
    public function leer(UploadedFile $imagen): array
    {
        $medidas = @getimagesize($imagen->getPathname());
        if (! $medidas || ! in_array($medidas[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $medidas[0] * $medidas[1] > 12000000) {
            throw ValidationException::withMessages(['imagen' => 'Usa una imagen PNG o JPEG de hasta 12 megapíxeles.']);
        }

        $proceso = new Process([
            config('sag.tesseract'),
            'stdin', 'stdout',
            '--psm', '11',
            '-c', 'tessedit_char_whitelist=0123456789,; ',
            '-c', 'preserve_interword_spaces=1',
        ], env: ['OMP_THREAD_LIMIT' => '1'], input: $imagen->get(), timeout: 12);
        try {
            $proceso->mustRun();
        } catch (ExceptionInterface) {
            abort(503, 'El lector de capturas no está disponible. Puedes ingresar los números de forma manual.');
        }

        preg_match_all('/(?<![0-9])[0-9]{1,6}(?![0-9])/', $proceso->getOutput(), $coincidencias);
        $numeros = array_values(array_unique(array_map('intval', $coincidencias[0])));
        if (count($numeros) > NumeracionDiagramaPallets::MAXIMO_MARCAS) {
            throw ValidationException::withMessages(['imagen' => 'La captura trae demasiados números. Recórtala y vuelve a probar.']);
        }

        return $numeros;
    }
}
