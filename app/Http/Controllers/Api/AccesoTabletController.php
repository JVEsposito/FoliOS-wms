<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccesoTabletRequest;
use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\Operacion\ServicioDisponibilidadCamareros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AccesoTabletController extends Controller
{
    public function store(
        AccesoTabletRequest $request,
        AlcanceOperacionalUsuario $alcance,
        CatalogoModulosAcceso $catalogoModulos,
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

        $modulosTablet = $catalogoModulos->modulosTabletUsuario($usuario);

        if ($modulosTablet === []) {
            throw ValidationException::withMessages([
                'email' => 'El perfil del usuario se encuentra inactivo o no posee módulos tablet habilitados.',
            ]);
        }

        $dispositivo = Dispositivo::query()
            ->where('codigo', $datos['codigo_dispositivo'])
            ->where('activo', true)
            ->first();

        if (! $dispositivo) {
            throw ValidationException::withMessages([
                'codigo_dispositivo' => 'La tablet no está autorizada o se encuentra inactiva.',
            ]);
        }

        $nuevoToken = DB::transaction(function () use ($usuario, $dispositivo, $modulosTablet) {
            $usuario->tokens()
                ->where('dispositivo_id', $dispositivo->id)
                ->delete();

            $dispositivo->update(['ultimo_acceso_at' => now()]);

            return $usuario->crearTokenParaDispositivo(
                $dispositivo,
                "tablet-{$dispositivo->codigo}",
                array_map(
                    CatalogoModulosAcceso::habilidadTablet(...),
                    $modulosTablet,
                ),
            );
        });
        $usuario->withAccessToken($nuevoToken->accessToken);
        $capacidades = $alcance->capacidadesApi($usuario);

        return response()->json([
            'token' => $nuevoToken->plainTextToken,
            'token_type' => 'Bearer',
            'sesion' => [
                'id' => (string) $nuevoToken->accessToken->getKey(),
                'iniciada_at' => $nuevoToken->accessToken->created_at?->toAtomString(),
            ],
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'debe_cambiar_password' => $usuario->debe_cambiar_password,
                'rol' => $usuario->rol->value,
                'perfil_acceso' => $capacidades['perfil_acceso'],
                'modulos_acceso' => $capacidades['modulos_acceso'],
                'modulos_tablet' => $modulosTablet,
                'ambito_camaras' => $alcance->ambitoCamaras($usuario),
                'capacidades' => $capacidades,
            ],
            'dispositivo' => [
                'id' => $dispositivo->id,
                'codigo' => $dispositivo->codigo,
                'nombre' => $dispositivo->nombre,
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            app(ServicioDisponibilidadCamareros::class)->desconectar((int) $token->getKey());
            $token->delete();
        }

        return response()->noContent();
    }
}
