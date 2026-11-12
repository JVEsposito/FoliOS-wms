<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un usuario de cliente solo llega al portal. Se niega por defecto cualquier otra
 * ruta de la API, tenga o no su propio permiso, para que una ruta interna sin
 * autorización explícita nunca exponga datos de la planta ni de otro cliente.
 */
class RestringirUsuarioCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            return $next($request);
        }

        $usuario = Auth::guard('sanctum')->user();
        if (! $usuario instanceof User || ! $usuario->esCliente() || $this->permitida($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Tu cuenta de cliente solo tiene acceso al portal de clientes.',
            'codigo' => 'solo_portal_clientes',
        ], Response::HTTP_FORBIDDEN);
    }

    private function permitida(Request $request): bool
    {
        return $request->is('api/portal', 'api/portal/*')
            || ($request->is('api/acceso-oficina') && in_array($request->method(), ['GET', 'HEAD', 'POST', 'DELETE'], true))
            || ($request->is('api/usuario/perfil') && in_array($request->method(), ['GET', 'HEAD'], true))
            || ($request->is('api/usuario/foto-perfil') && in_array($request->method(), ['GET', 'HEAD', 'POST', 'DELETE'], true))
            || ($request->is('api/usuario/password') && $request->isMethod('PUT'));
    }
}
