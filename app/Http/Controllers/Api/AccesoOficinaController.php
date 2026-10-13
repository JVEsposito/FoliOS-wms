<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccesoOficinaRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Autorizacion\IdentidadOficina;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AccesoOficinaController extends Controller
{
    public function store(
        AccesoOficinaRequest $request,
        AlcanceOperacionalUsuario $alcance,
        IdentidadOficina $identidad,
    ): JsonResponse {
        $datos = $request->validated();
        $usuario = User::query()
            ->where('email', mb_strtolower($datos['email']))
            ->first();

        if (! $usuario
            || ! $usuario->activo
            || ! Hash::check($datos['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales ingresadas no son válidas.',
            ]);
        }

        if (! $alcance->puedeAccederOficina($usuario)) {
            throw ValidationException::withMessages([
                'email' => 'El usuario no posee acceso a los módulos de oficina.',
            ]);
        }

        $token = $usuario->createToken(
            'oficina-'.now()->format('Ymd-His'),
            ['oficina'],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'usuario' => $identidad->para($usuario),
        ]);
    }

    /**
     * Identidad vigente de la sesión de oficina, con su pantalla de inicio.
     */
    public function show(
        Request $request,
        AlcanceOperacionalUsuario $alcance,
        IdentidadOficina $identidad,
    ): JsonResponse {
        $usuario = $request->user();
        abort_unless($usuario instanceof User && $alcance->puedeAccederOficina($usuario), Response::HTTP_FORBIDDEN);

        return response()
            ->json(['usuario' => $identidad->para($usuario)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function autorizarDemo(Request $request): JsonResponse
    {
        $usuario = $request->user();
        if (! $usuario || $usuario->rol !== RolUsuario::Administrador) {
            return response()->json([
                'message' => 'Solo un administrador puede habilitar la versión demo.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => [
                'autorizado' => true,
                'version_escenario' => 1,
                'administrador' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                ],
                'autorizado_at' => now()->toAtomString(),
                'persistencia' => 'sesion_navegador',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
