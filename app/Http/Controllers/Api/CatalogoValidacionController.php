<?php

namespace App\Http\Controllers\Api;

use App\Enums\MotivoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Http\Controllers\Controller;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\CatalogoOperativoValidacion;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CatalogoValidacionController extends Controller
{
    public function __invoke(
        Request $request,
        ServicioTemporadaActiva $temporadas,
        CatalogoOperativoValidacion $operativo,
    ): Response {
        $temporada = $temporadas->buscar();
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
        $respuesta->headers->set('Access-Control-Expose-Headers', 'ETag');

        return $respuesta;
    }
}
