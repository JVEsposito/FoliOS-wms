<?php

namespace App\Http\Controllers\Api;

use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Http\Controllers\Controller;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\CatalogoOperativoValidacion;
use App\Services\Validacion\TransferenciaCatalogoValidacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CatalogoValidacionController extends Controller
{
    public function __invoke(
        Request $request,
        ServicioTemporadaActiva $temporadas,
        CatalogoOperativoValidacion $operativo,
    ): Response {
        if ($request->query('formato') === 'fragmentos-v1') {
            $inicio = hrtime(true);
            $temporada = $temporadas->buscar();
            abort_unless($temporada, 404, 'No existe una temporada activa para validación.');
            $etag = "validacion-partes-v1-{$temporada->id}-{$temporada->version_catalogo}";
            $condicional = $this->configurarCache(response('', 200), $etag);
            if ($condicional->isNotModified($request)) {
                return $condicional;
            }

            $manifiesto = app(TransferenciaCatalogoValidacion::class)->manifiesto($temporada->id, $temporada->version_catalogo);

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
        $respuesta->setVary('Authorization');
        $respuesta->headers->set('Access-Control-Expose-Headers', 'ETag, X-Catalog-Bytes, X-Catalog-Fragments, X-Catalog-Target, Server-Timing');

        return $respuesta;
    }
}
