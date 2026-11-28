<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RegistrarFallasApi
{
    public const ATRIBUTO_REFERENCIA = 'referencia_solicitud';

    public function handle(Request $request, Closure $next): Response
    {
        $inicio = hrtime(true);
        $referencia = (string) Str::ulid();
        $request->attributes->set(self::ATRIBUTO_REFERENCIA, $referencia);

        $respuesta = $next($request);
        $estado = $respuesta->getStatusCode();
        if ($estado < 500) {
            return $respuesta;
        }

        Log::warning('Solicitud API fallida', [
            'referencia' => $referencia,
            'metodo' => $request->method(),
            'ruta' => $request->route()?->uri(),
            'estado' => $estado,
            'duracion_ms' => (int) round((hrtime(true) - $inicio) / 1_000_000),
        ]);

        $respuesta->headers->set('X-Request-Id', $referencia);
        if ($respuesta instanceof JsonResponse) {
            $datos = $respuesta->getData(true);
            if (is_array($datos) && ! array_is_list($datos)) {
                $datos['referencia'] = $referencia;
                if ($estado === 500 && ($datos['message'] ?? null) === 'Server Error') {
                    $datos['message'] = "Ocurrió un error en el servidor. Referencia {$referencia}.";
                }
                $respuesta->setData($datos);
            }
        }

        return $respuesta;
    }
}
