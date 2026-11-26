<?php

namespace App\Services\InspeccionSag;

use App\Models\LlamadaIaAsistente;
use App\Models\User;
use App\Services\Asistente\Haiku\ClienteAnthropic;
use App\Services\Asistente\Haiku\FallaHaiku;
use App\Services\Asistente\Haiku\PresupuestoHaiku;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Lee el «Certificado de Pre-Muestreo USDA/SAG» escaneado. Claude (con la clave del
 * asistente) devuelve N.º de lote, rangos y la tabla de cajas por columna. Tesseract lee
 * la misma página y confirma cada caja. Las contradicciones del propio documento
 * (cantidad, orden, partida, pallets) se devuelven como problemas: la oficina no aplica nada.
 */
class LectorPresampleUsda
{
    public const HERRAMIENTA = 'leer_presample';

    /** Lo que se reserva del tope mensual antes de enviar una página: una lectura cuesta menos. */
    private const RESERVA_USD = 0.02;

    private const SISTEMA = <<<'TXT'
        Lees certificados escaneados de pre-muestreo USDA/SAG de fruta de exportación chilena.
        Copia los números exactamente como están impresos. Si un dato no aparece o no se lee, déjalo en null; nunca lo inventes ni lo calcules.
        La tabla «Números de Cajas Muestra» se lee por columnas: cada columna de arriba abajo, y las columnas de izquierda a derecha.
        Los números del encabezado (tamaño de la partida, de la muestra, n.º de pallets, fechas, horas) no son cajas.
        TXT;

    public function __construct(
        private readonly ClienteAnthropic $cliente,
        private readonly PresupuestoHaiku $presupuesto,
    ) {}

    /**
     * @return array{fuente: string, lote: ?string, partida: ?int, muestra: ?int, pallets: ?int,
     *     rangos: list<array{desde: string, hasta: string}>, cajas: list<array{numero: int, estado: string}>,
     *     problemas: list<string>, avisos: list<string>}
     */
    public function leer(UploadedFile $archivo, User $usuario): array
    {
        $pdf = $this->esPdf($archivo);
        $this->validar($archivo, $pdf);
        $avisos = [];
        $tesseract = $this->tesseract($archivo, $pdf, $avisos);
        $claude = $this->claude($archivo, $pdf, $usuario, $avisos);

        if ($claude === null) {
            if ($tesseract === null) {
                abort(503, 'No se pudo leer el presample. Puedes ingresar los números de forma manual.');
            }
            array_unshift($avisos, 'Claude no está disponible: los números son la lectura de Tesseract y no traen lote ni rangos. Revisa cada uno en el papel.');

            return ['fuente' => 'tesseract', 'lote' => null, 'partida' => null, 'muestra' => null, 'pallets' => null, 'rangos' => [],
                'cajas' => array_map(fn (int $n): array => ['numero' => $n, 'estado' => 'revisar'], $this->cajasDeTesseract($tesseract)),
                'problemas' => [], 'avisos' => $avisos];
        }

        $numeros = array_merge(...array_map(fn ($columna): array => array_map('intval', (array) $columna), (array) ($claude['columnas_cajas'] ?? [])));
        $lectura = [
            'fuente' => 'claude',
            'lote' => filled($claude['lote'] ?? null) ? trim((string) $claude['lote']) : null,
            'partida' => $this->entero($claude['tamano_partida'] ?? null),
            'muestra' => $this->entero($claude['tamano_muestra'] ?? null),
            'pallets' => $this->entero($claude['numero_pallets'] ?? null),
            'rangos' => array_values(array_map(fn ($r): array => ['desde' => trim((string) ($r['desde'] ?? '')), 'hasta' => trim((string) ($r['hasta'] ?? ''))],
                array_filter((array) ($claude['rangos_lote'] ?? []), fn ($r): bool => filled($r['desde'] ?? null) || filled($r['hasta'] ?? null)))),
            'cajas' => array_map(fn (int $n): array => ['numero' => $n, 'estado' => $tesseract !== null && isset($tesseract[$n]) ? 'confirmada' : 'revisar'], $numeros),
        ];
        if ($lectura['lote'] === null) {
            $avisos[] = 'No se encontró el N.º de lote en el presample.';
        }
        if ($lectura['muestra'] === null) {
            $avisos[] = 'El presample no indica el tamaño de la muestra: cuenta las cajas en el papel.';
        }

        return [...$lectura, 'problemas' => $this->problemas($lectura, $numeros), 'avisos' => $avisos];
    }

    /** Contradicciones del propio documento: cualquiera impide aplicar la lectura. */
    private function problemas(array $lectura, array $numeros): array
    {
        $problemas = [];
        if ($numeros === []) {
            return ['No se encontraron cajas en el presample. Ingrésalas a mano.'];
        }
        if ($lectura['muestra'] !== null && count($numeros) !== $lectura['muestra']) {
            $problemas[] = sprintf('Se leyeron %d cajas y el presample indica una muestra de %d. Revisa el papel.', count($numeros), $lectura['muestra']);
        }
        // Columna por columna, el listado del presample va en orden creciente: un salto atrás es una lectura errada.
        foreach ($numeros as $i => $numero) {
            if ($i > 0 && $numero <= $numeros[$i - 1]) {
                $problemas[] = sprintf('Las cajas no van en orden: %d viene después de %d. Revisa el papel.', $numero, $numeros[$i - 1]);
                break;
            }
        }
        $sobre = $lectura['partida'] === null ? [] : array_values(array_filter($numeros, fn (int $n): bool => $n < 1 || $n > $lectura['partida']));
        if ($sobre !== []) {
            $problemas[] = sprintf('La caja %d pasa del tamaño de la partida (%d). Revisa el papel.', $sobre[0], $lectura['partida']);
        }
        if ($lectura['rangos'] !== []) {
            $suma = 0;
            foreach ($lectura['rangos'] as $rango) {
                if (! ctype_digit($rango['desde']) || ! ctype_digit($rango['hasta']) || (int) $rango['desde'] > (int) $rango['hasta']) {
                    return [...$problemas, sprintf('El rango de lote «%s a %s» no se pudo leer. Revisa el papel.', $rango['desde'], $rango['hasta'])];
                }
                $suma += (int) $rango['hasta'] - (int) $rango['desde'] + 1;
            }
            if ($lectura['pallets'] !== null && $suma !== $lectura['pallets']) {
                $problemas[] = sprintf('El rango de lote suma %d pallets y el presample indica %d. Revisa el papel.', $suma, $lectura['pallets']);
            }
        }

        return $problemas;
    }

    /** @return array<string, mixed>|null  la entrada de la herramienta, o null si Claude no se pudo usar */
    private function claude(UploadedFile $archivo, bool $pdf, User $usuario, array &$avisos): ?array
    {
        if (! $this->presupuesto->puedeGastar(self::RESERVA_USD)) {
            return null;
        }
        $bloque = $pdf
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($archivo->get())]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $archivo->getMimeType(), 'data' => base64_encode($archivo->get())]];
        $inicio = hrtime(true);
        try {
            $respuesta = $this->cliente->mensajes([
                'model' => $this->cliente->modelo(),
                'max_tokens' => 2000,
                'system' => self::SISTEMA,
                'tools' => [$this->herramienta()],
                'tool_choice' => ['type' => 'tool', 'name' => 'registrar_presample'],
                'messages' => [['role' => 'user', 'content' => [$bloque, ['type' => 'text', 'text' => 'Registra los datos de este presample.']]]],
            ]);
        } catch (FallaHaiku $falla) {
            $this->presupuesto->registrar($usuario, LlamadaIaAsistente::ERROR, [], $this->milisegundos($inicio), self::HERRAMIENTA, null, $falla->getMessage());

            return null;
        }
        $entrada = collect($respuesta['content'] ?? [])->firstWhere('type', 'tool_use')['input'] ?? null;
        $this->presupuesto->registrar($usuario, is_array($entrada) ? LlamadaIaAsistente::OK : LlamadaIaAsistente::ERROR,
            (array) ($respuesta['usage'] ?? []), $this->milisegundos($inicio), self::HERRAMIENTA, null, is_array($entrada) ? null : 'sin herramienta');

        return is_array($entrada) ? $entrada : null;
    }

    private function herramienta(): array
    {
        $entero = ['type' => ['integer', 'null']];

        return [
            'name' => 'registrar_presample',
            'description' => 'Registra los datos impresos en el certificado de pre-muestreo.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'lote' => ['type' => ['string', 'null'], 'description' => '«Nro. de Lote» del encabezado.'],
                    'tamano_partida' => [...$entero, 'description' => '«Tamaño de la Partida».'],
                    'tamano_muestra' => [...$entero, 'description' => '«Tamaño de la Muestra».'],
                    'numero_pallets' => [...$entero, 'description' => '«Nro. de Pallets».'],
                    'rangos_lote' => ['type' => 'array', 'description' => 'Filas «LOTE: DE … A …» y siguientes con números; vacío si no hay.',
                        'items' => ['type' => 'object', 'properties' => ['desde' => ['type' => 'string'], 'hasta' => ['type' => 'string']], 'required' => ['desde', 'hasta']]],
                    'columnas_cajas' => ['type' => 'array', 'description' => '«Números de Cajas Muestra»: una lista por columna, de arriba abajo; columnas de izquierda a derecha.',
                        'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                ],
                'required' => ['lote', 'tamano_partida', 'tamano_muestra', 'numero_pallets', 'rangos_lote', 'columnas_cajas'],
            ],
        ];
    }

    /** @return array<int, true>|null  números leídos por Tesseract, o null si no se pudo leer */
    private function tesseract(UploadedFile $archivo, bool $pdf, array &$avisos): ?array
    {
        $carpeta = storage_path('app/tmp/presample-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($carpeta);
        try {
            $imagen = $archivo->getPathname();
            if ($pdf) {
                (new Process([config('sag.pdftoppm'), '-r', '300', '-png', '-f', '1', '-l', '1', '-singlefile', $archivo->getPathname(), $carpeta.'/pagina'], timeout: 20))->mustRun();
                $imagen = $carpeta.'/pagina.png';
            }
            // --psm 4 (columna de texto variable): con el escaneo real del 25-11-2026 confirmó 63 de 69 cajas; --psm 6, 54.
            $proceso = new Process([config('sag.tesseract'), $imagen, 'stdout', '--psm', '4', '-c', 'tessedit_char_whitelist=0123456789 '],
                env: ['OMP_THREAD_LIMIT' => '1'], timeout: 20);
            $proceso->mustRun();
            preg_match_all('/(?<![0-9])[0-9]{1,6}(?![0-9])/', $proceso->getOutput(), $coincidencias);

            return array_fill_keys(array_map('intval', $coincidencias[0]), true);
        } catch (ExceptionInterface) {
            $avisos[] = 'Tesseract no pudo leer la página: ninguna caja queda confirmada por una segunda lectura.';

            return null;
        } finally {
            File::deleteDirectory($carpeta);
        }
    }

    /** Sin Claude no hay estructura: se quitan folios y números largos, y el resto queda por revisar. */
    private function cajasDeTesseract(array $leidos): array
    {
        return array_values(array_filter(array_keys($leidos), fn (int $n): bool => $n >= 1 && $n <= 99999));
    }

    private function validar(UploadedFile $archivo, bool $pdf): void
    {
        if ($pdf) {
            $paginas = preg_match_all('/\/Type\s*\/Page(?!s)\b/', (string) $archivo->get());
            if ($paginas > 1) {
                throw ValidationException::withMessages(['archivo' => 'Sube el presample como una sola página.']);
            }

            return;
        }
        $medidas = @getimagesize($archivo->getPathname());
        if (! $medidas || ! in_array($medidas[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $medidas[0] * $medidas[1] > 40000000) {
            throw ValidationException::withMessages(['archivo' => 'Usa el PDF escaneado o una imagen PNG o JPEG.']);
        }
    }

    private function esPdf(UploadedFile $archivo): bool
    {
        return str_starts_with((string) file_get_contents($archivo->getPathname(), length: 5), '%PDF-');
    }

    private function entero(mixed $valor): ?int
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    private function milisegundos(int $inicio): int
    {
        return (int) round((hrtime(true) - $inicio) / 1_000_000);
    }
}
