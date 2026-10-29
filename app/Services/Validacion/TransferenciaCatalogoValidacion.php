<?php

namespace App\Services\Validacion;

use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fragmentos estables por ID: una edición no desplaza todas las páginas del catálogo. */
class TransferenciaCatalogoValidacion
{
    private const COLUMNAS = [
        'categorias' => ['id', 'nombre', 'codigo_externo'],
        'articulos' => ['id', 'variedad_validacion_id', 'especie', 'variedad', 'calibre', 'envase', 'codigo_externo'],
        'origenes' => ['id', 'cliente_validacion_id', 'csg_validacion_id', 'variedad_ids', 'cliente', 'marca', 'csg', 'predio', 'codigo_externo'],
        'combinaciones' => ['id', 'articulo_validacion_id', 'origen_validacion_id', 'codigo_externo'],
    ];

    public function manifiesto(string $temporadaId, int $version): array
    {
        $clave = $this->clave($temporadaId, $version);
        if ($existente = Cache::get($clave)) {
            return $existente;
        }
        // Las demás PDA reintentan; no ocupan workers esperando al mismo generador.
        $bloqueo = Cache::lock('validacion-transferencia-v1:'.$temporadaId.':publicar', 120);
        if (! $bloqueo->get()) {
            throw new HttpException(503, 'El catálogo se está preparando. Reintenta en unos segundos.', null, ['Retry-After' => '3']);
        }
        try {
            if ($existente = Cache::get($clave)) {
                return $existente;
            }
            [$temporada, $datos] = DB::transaction(function () use ($temporadaId, $version): array {
                $temporada = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido();
                abort_unless($temporada && $temporada->id === $temporadaId && $temporada->version_catalogo === $version,
                    409, 'El catálogo cambió mientras se preparaba. Reintenta para recibir la versión vigente.');

                return [$temporada, app(CatalogoOperativoValidacion::class)->datos($temporadaId)];
            });
            // Codificación y caché fuera de la transacción que protege los maestros.
            $fragmentos = [];
            foreach (self::COLUMNAS as $coleccion => $columnas) {
                $grupos = $datos[$coleccion]->groupBy(fn ($fila) => in_array($coleccion, ['categorias', 'origenes'], true)
                    ? 0 : hexdec(substr(str_replace('-', '', $fila->id), 0, 2)) % 32)->sortKeys();
                foreach ($grupos as $grupo => $filas) {
                    $cuerpo = $this->codificar($coleccion, $columnas, $filas->sortBy('id')->values()->all());
                    $hash = hash('sha256', $cuerpo);
                    abort_unless(Cache::put('validacion-fragmento-v1:'.$hash, $cuerpo, now()->addDay()),
                        503, 'No se pudo guardar una parte del catálogo en el servidor. Reintenta la sincronización.');
                    $fragmentos[] = ['coleccion' => $coleccion, 'grupo' => (int) $grupo,
                        'hash' => $hash, 'bytes' => strlen($cuerpo), 'filas' => $filas->count()];
                }
            }
            $manifiesto = [
                'formato' => 'fragmentos-v1',
                'temporada' => $temporada->toArray(),
                'tipos_bulto' => [TipoBulto::Pallet->value, TipoBulto::Saldo->value],
                'resultados' => array_column(ResultadoValidacionPallet::cases(), 'value'),
                'motivos' => array_column(MotivoValidacionPallet::cases(), 'value'),
                'generado_at' => now()->toAtomString(),
                'cantidades' => array_map(fn ($filas) => $filas->count(), $datos),
                'fragmentos' => $fragmentos,
            ];
            // El manifiesto solo se hace visible después de almacenar todas sus partes.
            abort_unless(Cache::put($clave, $manifiesto, now()->addHours(6)),
                503, 'No se pudo publicar el catálogo en el servidor. Se conserva la copia anterior de la PDA.');
            $this->retirarVersionesAntiguas($temporadaId, $version, $fragmentos);

            return $manifiesto;
        } finally {
            $bloqueo->release();
        }
    }

    public function fragmento(string $temporadaId, int $version, string $hash, ?string $base = null): string
    {
        $manifiesto = Cache::get($this->clave($temporadaId, $version));
        abort_unless($manifiesto && collect($manifiesto['fragmentos'])->contains('hash', $hash),
            409, 'La descarga venció o el catálogo cambió. Reintenta la sincronización; las partes recibidas se conservan.');
        $cuerpo = Cache::get('validacion-fragmento-v1:'.$hash);
        if (! is_string($cuerpo) || hash('sha256', $cuerpo) !== $hash) {
            Cache::forget($this->clave($temporadaId, $version));
            abort(409, 'Falta una parte del catálogo. Reintenta para reconstruir la descarga.');
        }

        // Una edición extensa puede tocar muchos grupos. Dentro de cada grupo también
        // se transfieren solo las filas cambiadas cuando la PDA tiene una base conocida.
        if ($base && preg_match('/^[a-f0-9]{64}$/', $base)) {
            $anterior = Cache::get('validacion-fragmento-v1:'.$base);
            if (is_string($anterior) && hash('sha256', $anterior) === $base) {
                $actual = json_decode($cuerpo, true, flags: JSON_THROW_ON_ERROR);
                $previo = json_decode($anterior, true, flags: JSON_THROW_ON_ERROR);
                if ($actual['coleccion'] === $previo['coleccion']) {
                    $filasActuales = $this->decodificar($actual);
                    $filasPrevias = $this->decodificar($previo);
                    $cambios = array_filter($filasActuales, fn ($fila, $id) => ! isset($filasPrevias[$id]) || $filasPrevias[$id] !== $fila, ARRAY_FILTER_USE_BOTH);
                    $delta = json_encode(['formato' => 'delta-fragmentos-v1', 'base' => $base, 'destino' => $hash,
                        'coleccion' => $actual['coleccion'], 'cambios' => array_values($cambios),
                        'eliminados' => array_values(array_diff(array_keys($filasPrevias), array_keys($filasActuales)))],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
                    if (strlen($delta) < strlen($cuerpo)) {
                        return $delta;
                    }
                }
            }
        }

        return $cuerpo;
    }

    private function clave(string $temporadaId, int $version): string
    {
        return 'validacion-transferencia-v1:'.$temporadaId.':'.$version;
    }

    /** Diccionario local: los textos y las referencias repetidas viajan una sola vez por parte. */
    private function codificar(string $coleccion, array $columnas, array $filas): string
    {
        $textos = [];
        $indices = [];
        $codificar = function ($valor) use (&$codificar, &$textos, &$indices) {
            if ($valor === null) {
                return null;
            }
            if (is_array($valor)) {
                sort($valor, SORT_STRING);

                return array_map($codificar, $valor);
            }
            $texto = (string) $valor;
            if (! array_key_exists($texto, $indices)) {
                $indices[$texto] = count($textos);
                $textos[] = $texto;
            }

            return $indices[$texto];
        };
        $registros = [];
        foreach ($filas as $fila) {
            $registros[] = array_map(fn ($columna) => $codificar($fila->{$columna} ?? null), $columnas);
        }

        return json_encode(['formato' => 'fragmentos-v1', 'coleccion' => $coleccion, 'columnas' => $columnas,
            'textos' => $textos, 'filas' => $registros], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    private function decodificar(array $fragmento): array
    {
        $valor = fn ($indice) => $indice === null ? null : $fragmento['textos'][$indice];
        $filas = [];
        foreach ($fragmento['filas'] as $fila) {
            $valores = array_map(fn ($indice) => is_array($indice) ? array_map($valor, $indice) : $valor($indice), $fila);
            $registro = array_combine($fragmento['columnas'], $valores);
            $filas[$registro['id']] = $registro;
        }

        return $filas;
    }

    private function retirarVersionesAntiguas(string $temporadaId, int $version, array $fragmentos): void
    {
        $clave = 'validacion-transferencia-v1:'.$temporadaId.':versiones';
        $versiones = Cache::get($clave, []);
        $versiones[$version] = array_column($fragmentos, 'hash');
        krsort($versiones, SORT_NUMERIC);
        $conservar = array_slice($versiones, 0, 3, true);
        $hashesVigentes = array_flip(array_merge(...array_values($conservar)));
        foreach (array_diff_key($versiones, $conservar) as $anterior => $hashes) {
            Cache::forget($this->clave($temporadaId, $anterior));
            foreach ($hashes as $hash) {
                if (! isset($hashesVigentes[$hash])) {
                    Cache::forget('validacion-fragmento-v1:'.$hash);
                }
            }
        }
        Cache::forever($clave, $conservar);
    }
}
