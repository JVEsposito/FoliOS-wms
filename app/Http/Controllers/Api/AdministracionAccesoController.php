<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarUsuarioAdministracionRequest;
use App\Http\Requests\CrearDispositivoAdministracionRequest;
use App\Http\Requests\CrearUsuarioAdministracionRequest;
use App\Models\Dispositivo;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AdministracionAccesoController extends Controller
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('consultar-accesos');

        return response()->json([
            'usuarios' => User::query()
                // Los usuarios de clientes se administran en su propia sección.
                ->where('rol', '!=', RolUsuario::Cliente->value)
                ->with('perfilAcceso')
                ->orderBy('name')
                ->orderBy('email')
                ->get()
                ->map(fn (User $usuario): array => $this->usuario($usuario)),
            'dispositivos' => Dispositivo::query()
                ->orderBy('codigo')
                ->get()
                ->map(fn (Dispositivo $dispositivo): array => $this->dispositivo($dispositivo)),
        ]);
    }

    public function crearUsuario(CrearUsuarioAdministracionRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $perfil = $this->resolverPerfil($datos);
        $rol = $perfil?->rol_base ?? RolUsuario::from($datos['rol']);
        $usuario = User::create([
            'name' => $datos['nombre'],
            'email' => $datos['email'],
            'password' => $datos['password'],
            'debe_cambiar_password' => true,
            'rol' => $rol,
            'perfil_acceso_id' => $perfil?->id,
            'activo' => (bool) ($datos['activo'] ?? true),
        ]);

        return response()->json(
            ['usuario' => $this->usuario($usuario)],
            Response::HTTP_CREATED,
        );
    }

    public function actualizarUsuario(
        ActualizarUsuarioAdministracionRequest $request,
        User $usuario,
    ): JsonResponse {
        abort_if($usuario->esCliente(), Response::HTTP_NOT_FOUND);
        $datos = $request->validated();
        $actor = $request->user();

        $resultado = DB::transaction(function () use ($datos, $usuario, $actor): array {
            $usuario = User::query()->with('perfilAcceso')->lockForUpdate()->findOrFail($usuario->id);
            $perfil = $this->resolverPerfil($datos);
            $rolDestino = $perfil?->rol_base ?? RolUsuario::from($datos['rol']);
            $eraAdministradorActivo = $usuario->activo
                && $usuario->rol === RolUsuario::Administrador;
            $seguiraAdministradorActivo = (bool) $datos['activo']
                && $rolDestino === RolUsuario::Administrador;

            if ($eraAdministradorActivo && ! $seguiraAdministradorActivo) {
                $administradoresActivos = User::query()
                    ->where('activo', true)
                    ->where('rol', RolUsuario::Administrador->value)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count();

                if ($administradoresActivos <= 1) {
                    throw new DomainException(
                        'No puedes desactivar ni cambiar el rol del último administrador activo.',
                    );
                }
            }

            if ($actor->id === $usuario->id && ! $seguiraAdministradorActivo) {
                throw new DomainException(
                    'No puedes quitarte tu propio acceso administrativo ni desactivar tu cuenta.',
                );
            }

            $rolAnterior = $usuario->rol;
            $emailAnterior = $usuario->email;
            $activoAnterior = $usuario->activo;
            $perfilAnterior = $usuario->perfil_acceso_id;
            $cambiaPassword = filled($datos['password'] ?? null);

            $usuario->fill([
                'name' => $datos['nombre'],
                'email' => $datos['email'],
                'rol' => $rolDestino,
                'perfil_acceso_id' => $perfil?->id,
                'activo' => (bool) $datos['activo'],
            ]);
            if ($cambiaPassword) {
                $usuario->password = $datos['password'];
                $usuario->debe_cambiar_password = true;
            }
            $usuario->save();

            $cambioSeguridad = $cambiaPassword
                || $rolAnterior !== $usuario->rol
                || $perfilAnterior !== $usuario->perfil_acceso_id
                || $emailAnterior !== $usuario->email
                || $activoAnterior !== $usuario->activo;

            if ($cambioSeguridad) {
                $usuario->tokens()->delete();
            }

            return [
                'usuario' => $this->usuario($usuario->refresh()),
                'sesiones_revocadas' => $cambioSeguridad,
                'sesion_actual_invalidada' => $cambioSeguridad && $actor->id === $usuario->id,
            ];
        }, attempts: 3);

        return response()->json($resultado);
    }

    public function crearDispositivo(CrearDispositivoAdministracionRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $dispositivo = Dispositivo::create([
            'codigo' => $datos['codigo'],
            'nombre' => $datos['nombre'],
            'plataforma' => 'android',
            'activo' => true,
        ]);

        return response()->json(
            ['dispositivo' => $this->dispositivo($dispositivo)],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function usuario(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->name,
            'email' => $usuario->email,
            'rol' => $usuario->rol->value,
            'perfil' => $usuario->perfilAcceso ? [
                'id' => $usuario->perfilAcceso->id,
                'codigo' => $usuario->perfilAcceso->codigo,
                'nombre' => $usuario->perfilAcceso->nombre,
            ] : null,
            'activo' => $usuario->activo,
            'debe_cambiar_password' => $usuario->debe_cambiar_password,
            'pin_operacional_configurado' => $usuario->pin_operacional_hash !== null,
            'permisos' => $this->alcance->capacidadesApi($usuario),
            'creado_at' => $usuario->created_at?->toAtomString(),
            'actualizado_at' => $usuario->updated_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispositivo(Dispositivo $dispositivo): array
    {
        return [
            'id' => $dispositivo->id,
            'codigo' => $dispositivo->codigo,
            'nombre' => $dispositivo->nombre,
            'plataforma' => $dispositivo->plataforma,
            'activo' => $dispositivo->activo,
            'ultimo_acceso_at' => $dispositivo->ultimo_acceso_at?->toAtomString(),
            'creado_at' => $dispositivo->created_at?->toAtomString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function resolverPerfil(array $datos): ?PerfilAcceso
    {
        if (! filled($datos['perfil_acceso_id'] ?? null)) {
            if (! filled($datos['rol'] ?? null)) {
                return null;
            }

            $perfil = PerfilAcceso::query()
                ->where('rol_base', $datos['rol'])
                ->where('predeterminado', true)
                ->first();

            if (! $perfil?->activo) {
                throw new DomainException(
                    'El perfil inicial correspondiente al rol seleccionado no está disponible.',
                );
            }

            return $perfil;
        }

        return PerfilAcceso::query()
            ->whereKey($datos['perfil_acceso_id'])
            ->where('activo', true)
            ->firstOrFail();
    }
}
