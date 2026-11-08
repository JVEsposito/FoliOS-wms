<?php

namespace App\Services\Validacion;

use App\Jobs\PrepararCatalogoIncremental;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CatalogoIncrementalValidacion
{
    private const VERSIONES = 4;

    private const MAX_BYTES_CAMBIOS = 2_097_152;

    public function __construct(private readonly DescargaPaginadaCatalogoValidacion $descarga) {}

    public function publicada(string $temporada, int $version): bool
    {
        return is_file($this->directorio($temporada)."/{$version}/conteos.json");
    }

    public function cambios(string $temporada, int $base, int $version): ?array
    {
        $archivo = $this->directorio($temporada)."/{$version}/desde-{$base}.json";

        if (! is_file($archivo)) {
            return null;
        }
        try {
            return json_decode(File::get($archivo), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    /** Indica si el cliente puede esperar una preparación asíncrona antes de usar la descarga completa. */
    public function solicitar(string $temporada, int $version): bool
    {
        if (config('queue.default') !== 'database' || $this->publicada($temporada, $version)) {
            return false;
        }

        $clave = "catalogo-pt-incremental:solicitud:{$temporada}:{$version}";
        $ahora = now()->timestamp;
        if (Cache::add($clave, $ahora, 900)) {
            try {
                PrepararCatalogoIncremental::dispatch($temporada, $version);
            } catch (Throwable $error) {
                report($error);
                Cache::put($clave, 0, 900);

                return false;
            }
        }

        return (int) Cache::get($clave) > $ahora - 240;
    }

    /** Materializa una versión coherente en lotes y publica sus diferencias con las bases conservadas. */
    public function preparar(string $temporada, int $version): bool
    {
        $bloqueo = Cache::lock("catalogo-pt-incremental:publicar:{$temporada}", 900);
        if (! $bloqueo->get()) {
            return false;
        }

        $raiz = $this->directorio($temporada);
        $destino = "{$raiz}/{$version}";
        $temporal = "{$raiz}/temporal-".Str::uuid();
        try {
            if ($this->publicada($temporada, $version)) {
                return true;
            }
            $vigente = app(ServicioTemporadaActiva::class)->buscar();
            if ($vigente?->id !== $temporada || $vigente->version_catalogo !== $version) {
                return false;
            }

            File::ensureDirectoryExists($temporal);
            foreach (File::directories($raiz) as $directorio) {
                if ($directorio !== $temporal && str_starts_with(basename($directorio), 'temporal-')
                    && File::lastModified($directorio) < now()->subMinutes(15)->timestamp) {
                    File::deleteDirectory($directorio);
                }
            }

            $conteos = [];
            foreach (DescargaPaginadaCatalogoValidacion::COLECCIONES as $coleccion) {
                $conteos[$coleccion] = $this->guardarFilas($temporal, $temporada, $version, $coleccion);
            }
            $anteriores = array_slice($this->versiones($raiz), 0, self::VERSIONES - 1);
            foreach ($anteriores as $base) {
                if ($base < $version) {
                    $this->guardarCambios($raiz, $temporal, $base, $conteos);
                }
            }

            $marca = json_encode($conteos, JSON_THROW_ON_ERROR);
            if (File::put("{$temporal}/conteos.json", $marca) !== strlen($marca)) {
                throw new RuntimeException('No se pudo guardar el índice de la versión incremental del catálogo PT.');
            }
            if (! File::moveDirectory($temporal, $destino)) {
                throw new RuntimeException('No se pudo publicar la versión incremental del catálogo PT.');
            }

            foreach (array_slice($this->versiones($raiz), self::VERSIONES) as $antigua) {
                File::deleteDirectory("{$raiz}/{$antigua}");
            }

            return true;
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 409) {
                return false;
            }

            throw $error;
        } finally {
            try {
                File::deleteDirectory($temporal);
            } finally {
                $bloqueo->release();
            }
        }
    }

    private function guardarFilas(string $directorio, string $temporada, int $version, string $coleccion): int
    {
        $archivo = fopen("{$directorio}/{$coleccion}.jsonl", 'wb');
        $cursor = null;
        $cantidad = 0;
        try {
            do {
                $pagina = json_decode($this->descarga->pagina($temporada, $version, $coleccion, $cursor,
                    tamano: DescargaPaginadaCatalogoValidacion::TAMANOS_RAPIDOS[$coleccion]), true, flags: JSON_THROW_ON_ERROR);
                foreach ($pagina['filas'] as $fila) {
                    if ($coleccion === 'combinaciones') {
                        unset($fila['codigo_externo']);
                    }
                    $linea = json_encode($fila, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                    if (fwrite($archivo, $linea) !== strlen($linea)) {
                        throw new RuntimeException('No se pudo guardar una fila del catálogo PT.');
                    }
                    $cantidad++;
                }
                $cursor = $pagina['siguiente'];
            } while ($cursor !== null);
        } finally {
            fclose($archivo);
        }

        return $cantidad;
    }

    private function guardarCambios(string $raiz, string $destino, int $base, array $conteos): void
    {
        $cambios = [];
        $bytes = 0;
        foreach (DescargaPaginadaCatalogoValidacion::COLECCIONES as $coleccion) {
            $pares = $coleccion === 'combinaciones';
            $cambios[$coleccion] = ['altas' => [], 'retiros' => []];
            $antes = $this->filas("{$raiz}/{$base}/{$coleccion}.jsonl");
            $despues = $this->filas("{$destino}/{$coleccion}.jsonl");
            while ($antes->valid() || $despues->valid()) {
                $previa = $antes->current();
                $nueva = $despues->current();
                $orden = $previa === null ? 1 : ($nueva === null ? -1 : strcasecmp($previa['id'], $nueva['id']));
                if ($orden < 0 || ($pares && $orden === 0 && $previa !== $nueva)) {
                    $retiro = $pares ? $this->par($previa) : $previa['id'];
                    $cambios[$coleccion]['retiros'][] = $retiro;
                    $bytes += strlen(json_encode($retiro, JSON_THROW_ON_ERROR)) + 1;
                }
                if ($orden > 0 || ($orden === 0 && $previa !== $nueva)) {
                    $alta = $pares ? $this->par($nueva) : $nueva;
                    $cambios[$coleccion]['altas'][] = $alta;
                    $bytes += strlen(json_encode($alta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) + 1;
                }
                if ($bytes > self::MAX_BYTES_CAMBIOS) {
                    return;
                }
                if ($orden <= 0) {
                    $antes->next();
                }
                if ($orden >= 0) {
                    $despues->next();
                }
            }
        }

        $json = json_encode(['base' => $base, 'conteos' => $conteos, 'cambios' => $cambios],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (File::put("{$destino}/desde-{$base}.json", $json) !== strlen($json)) {
            throw new RuntimeException('No se pudieron guardar los cambios del catálogo PT.');
        }
    }

    private function filas(string $ruta): Generator
    {
        $archivo = fopen($ruta, 'rb');
        try {
            while (($linea = fgets($archivo)) !== false) {
                yield json_decode($linea, true, flags: JSON_THROW_ON_ERROR);
            }
            if (! feof($archivo)) {
                throw new RuntimeException('No se pudo leer la copia del catálogo PT.');
            }
        } finally {
            fclose($archivo);
        }
    }

    private function par(array $fila): array
    {
        return [$fila['articulo_validacion_id'], $fila['origen_validacion_id']];
    }

    /** @return list<int> */
    private function versiones(string $raiz): array
    {
        $versiones = [];
        foreach (File::directories($raiz) as $directorio) {
            $nombre = basename($directorio);
            if (ctype_digit($nombre) && is_file("{$directorio}/conteos.json")) {
                $versiones[] = (int) $nombre;
            }
        }
        rsort($versiones, SORT_NUMERIC);

        return $versiones;
    }

    private function directorio(string $temporada): string
    {
        return Storage::disk('local')->path("catalogo-pt-incremental/{$temporada}");
    }
}
