<?php

namespace App\Http\Controllers\Api;

use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Http\Controllers\Controller;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\CatalogoIncrementalValidacion;
use App\Services\Validacion\CatalogoOperativoValidacion;
use App\Services\Validacion\DescargaPaginadaCatalogoValidacion;
use App\Services\Validacion\TransferenciaCatalogoValidacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CatalogoValidacionController extends Controller
{
    public function __invoke(
        Request $request,
        ServicioTemporadaActiva $temporadas,
        CatalogoOperativoValidacion $operativo,
    ): Response {
        if ($request->query('formato') === 'paginas-v1') {
            $descarga = app(DescargaPaginadaCatalogoValidacion::class);
            $compacto = $request->query('transporte') === 'compacto-v1';
            $indice = $descarga->indice($compacto);
            $temporada = $indice['temporada'];
            $incremental = $compacto && $request->query('incremental') === 'delta-v1'
                ? app(CatalogoIncrementalValidacion::class) : null;
            $etag = "validacion-paginas-v1-{$temporada['id']}-{$temporada['version_catalogo']}";
            $condicional = $this->configurarCache(response('', 200), $etag);
            if ($condicional->isNotModified($request)) {
                $incremental?->solicitar($temporada['id'], $temporada['version_catalogo']);

                return $condicional;
            }
            if ($incremental !== null) {
                $datos = $request->validate(['temporada_base' => ['nullable', 'uuid'], 'version_base' => ['nullable', 'integer', 'min:1']]);
                $base = (int) ($datos['version_base'] ?? 0);
                $version = $temporada['version_catalogo'];
                if (($datos['temporada_base'] ?? null) === $temporada['id'] && $base > 0 && $base < $version) {
                    $inicio = hrtime(true);
                    $esperando = $incremental->publicada($temporada['id'], $base) && $incremental->solicitar($temporada['id'], $version);
                    $cambios = $incremental->cambios($temporada['id'], $base, $version);
                    if ($cambios !== null) {
                        $metadatos = array_intersect_key($indice, array_flip(['temporada', 'tipos_bulto', 'resultados', 'motivos', 'generado_at']));
                        $cuerpo = json_encode(['formato' => 'delta-v1', ...$metadatos, ...$cambios],
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                        return $this->respuestaPagina($request, $cuerpo, $inicio)->header('X-Catalog-Transfer', 'delta-v1');
                    }
                    if ($esperando) {
                        return response()->json([
                            'message' => 'El servidor está preparando los cambios del catálogo. Puedes seguir con la copia guardada.',
                        ], 202)->header('Retry-After', '30')->header('Cache-Control', 'no-store');
                    }
                }
            }
            if ($compacto) {
                $indice['rangos_combinaciones'] = $descarga->rangosCombinaciones($temporada['id']);
            }

            return $this->configurarCache(response()->json($indice), $etag);
        }
        if ($request->query('formato') === 'fragmentos-v1') {
            $inicio = hrtime(true);
            $temporada = $temporadas->buscar();
            abort_unless($temporada, 404, 'No existe una temporada activa para validación.');
            $etag = "validacion-partes-v1-{$temporada->id}-{$temporada->version_catalogo}";
            $condicional = $this->configurarCache(response('', 200), $etag);
            if ($condicional->isNotModified($request)) {
                return $condicional;
            }

            try {
                $manifiesto = app(TransferenciaCatalogoValidacion::class)->manifiesto($temporada->id, $temporada->version_catalogo);
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 503) {
                    throw $error;
                }
                // La publicación por partes es una optimización, no una condición para
                // operar. El contrato completo usa el mismo snapshot y ya es compatible
                // con las PDA instaladas. Nunca se libera un bloqueo de otro proceso.
                Log::warning('Catálogo de validación entregado completo por publicación de partes no disponible.', [
                    'temporada_id' => $temporada->id,
                    'version_catalogo' => $temporada->version_catalogo,
                    'motivo' => $error->getMessage(),
                ]);

                return DB::transaction(fn () => $this->catalogoConsistente($request, $temporadas, $operativo))
                    ->header('X-Catalog-Transfer', 'full-fallback')
                    ->header('Server-Timing', 'catalog;dur='.round((hrtime(true) - $inicio) / 1000000, 2));
            }

            return $this->configurarCache(response()->json($manifiesto)
                ->header('X-Catalog-Bytes', (string) array_sum(array_column($manifiesto['fragmentos'], 'bytes')))
                ->header('X-Catalog-Fragments', (string) count($manifiesto['fragmentos']))
                ->header('Server-Timing', 'catalog;dur='.round((hrtime(true) - $inicio) / 1000000, 2)), $etag);
        }

        return DB::transaction(fn () => $this->catalogoConsistente($request, $temporadas, $operativo));
    }

    public function fragmento(Request $request, string $temporadaId, int $version, string $hash): Response
    {
        $base = $request->query('base');
        abort_unless($base === null || (is_string($base) && preg_match('/^[a-f0-9]{64}$/', $base)), 422, 'La base de descarga no es válida.');
        $cuerpo = app(TransferenciaCatalogoValidacion::class)->fragmento($temporadaId, $version, $hash, $base);

        return $this->configurarCache(response($cuerpo)->header('Content-Type', 'application/json')
            ->header('X-Catalog-Bytes', (string) strlen($cuerpo))
            ->header('X-Catalog-Target', $hash), hash('sha256', $cuerpo));
    }

    public function pagina(Request $request, string $temporadaId, int $version, string $coleccion): Response
    {
        $datos = $request->validate(['desde' => ['nullable', 'uuid'], 'transporte' => ['nullable', 'in:compacto-v1'],
            'inicio' => ['nullable', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i'],
            'hasta' => ['nullable', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i']]);
        $compacto = ($datos['transporte'] ?? null) === 'compacto-v1';
        $desde = $datos['desde'] ?? null;
        $limite = $datos['inicio'] ?? null;
        $hasta = $datos['hasta'] ?? null;
        abort_if(($limite !== null || $hasta !== null) && (! $compacto || $coleccion !== 'combinaciones'), 422, 'El intervalo no corresponde a esta colección.');
        abort_if(($limite !== null && $hasta !== null && strcasecmp($limite, $hasta) >= 0)
            || ($desde !== null && (($limite !== null && strcasecmp($desde, $limite) < 0) || ($hasta !== null && strcasecmp($desde, $hasta) >= 0))), 422, 'El cursor está fuera del intervalo solicitado.');
        $inicio = hrtime(true);
        $cuerpo = app(DescargaPaginadaCatalogoValidacion::class)->pagina($temporadaId, $version, $coleccion, $desde, $compacto, $limite, $hasta);

        return $this->respuestaPagina($request, $cuerpo, $inicio);
    }

    private function respuestaPagina(Request $request, string $cuerpo, int $inicio): Response
    {
        $hash = hash('sha256', $cuerpo);
        $enviado = $cuerpo;
        if ((AcceptHeader::fromString($request->header('Accept-Encoding', ''))->get('gzip')?->getQuality() ?? 0) > 0 && strlen($cuerpo) > 2048) {
            $comprimido = gzencode($cuerpo, 4);
            if ($comprimido !== false && strlen($comprimido) < strlen($cuerpo)) {
                $enviado = $comprimido;
            }
        }
        $respuesta = response($enviado)->header('Content-Type', 'application/json');
        $respuesta->setVary('Accept-Encoding');
        if ($enviado !== $cuerpo) {
            $respuesta->headers->set('Content-Encoding', 'gzip');
        }

        return $this->configurarCache($respuesta
            ->header('X-Catalog-Bytes', (string) strlen($cuerpo))->header('X-Catalog-Target', $hash)
            ->header('Server-Timing', 'catalog;dur='.round((hrtime(true) - $inicio) / 1000000, 2)), hash('sha256', $enviado));
    }

    private function catalogoConsistente(Request $request, ServicioTemporadaActiva $temporadas, CatalogoOperativoValidacion $operativo): Response
    {
        $temporada = $temporadas->buscarConBloqueoCompartido();
        abort_unless($temporada, 404, 'No existe una temporada activa para validación.');

        $etag = sprintf(
            'validacion-catalogo-%s-%d',
            $temporada->id,
            $temporada->version_catalogo,
        );
        $respuestaCondicional = $this->configurarCache(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        $catalogo = $operativo->datos($temporada->id);

        return $this->configurarCache(
            response()->json([
                'temporada' => $temporada,
                ...$catalogo,
                'tipos_bulto' => [TipoBulto::Pallet->value, TipoBulto::Saldo->value],
                'resultados' => array_column(ResultadoValidacionPallet::cases(), 'value'),
                'motivos' => array_column(MotivoValidacionPallet::cases(), 'value'),
                'generado_at' => now()->toAtomString(),
            ]),
            $etag,
        );
    }

    private function configurarCache(Response $respuesta, string $etag): Response
    {
        $respuesta->setEtag($etag);
        $respuesta->setPrivate();
        $respuesta->headers->addCacheControlDirective('no-cache');
        $respuesta->setVary(array_unique([...$respuesta->getVary(), 'Authorization']));
        $respuesta->headers->set('Access-Control-Expose-Headers', 'ETag, X-Catalog-Bytes, X-Catalog-Fragments, X-Catalog-Target, X-Catalog-Transfer, Server-Timing');

        return $respuesta;
    }
}
