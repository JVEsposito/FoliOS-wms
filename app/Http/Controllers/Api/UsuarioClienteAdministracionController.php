<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Accesos → Usuarios de clientes: cuentas externas atadas a una o varias exportadoras. */
class UsuarioClienteAdministracionController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('consultar-accesos');

        return response()->json([
            'usuarios' => User::where('rol', RolUsuario::Cliente->value)->with('clientesPortal:id,codigo,nombre')
                ->orderBy('name')->get()->map(fn (User $u): array => $this->usuario($u))->values(),
            'clientes' => Cliente::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request, null);
        $usuario = DB::transaction(function () use ($datos): User {
            $usuario = User::create(['name' => $datos['nombre'], 'email' => $datos['email'], 'password' => $datos['password'],
                'debe_cambiar_password' => true, 'rol' => RolUsuario::Cliente, 'perfil_acceso_id' => null,
                'activo' => (bool) ($datos['activo'] ?? true)]);
            $this->sincronizar($usuario, $datos['clientes']);

            return $usuario;
        }, attempts: 3);

        return response()->json(['usuario' => $this->usuario($usuario->load('clientesPortal'))], Response::HTTP_CREATED);
    }

    public function update(Request $request, User $usuario): JsonResponse
    {
        abort_unless($usuario->esCliente(), Response::HTTP_NOT_FOUND);
        $datos = $this->validar($request, $usuario);
        $revocadas = DB::transaction(function () use ($datos, $usuario): bool {
            $usuario = User::lockForUpdate()->findOrFail($usuario->id);
            $antes = [$usuario->email, $usuario->activo, $usuario->clientesPortal()->pluck('clientes.id')->sort()->values()->all()];
            $usuario->fill(['name' => $datos['nombre'], 'email' => $datos['email'], 'activo' => (bool) $datos['activo']]);
            if (filled($datos['password'] ?? null)) {
                $usuario->password = $datos['password'];
                $usuario->debe_cambiar_password = true;
            }
            $usuario->save();
            $this->sincronizar($usuario, $datos['clientes']);
            $despues = [$usuario->email, $usuario->activo, collect($datos['clientes'])->sort()->values()->all()];
            // Cambiar credenciales, estado o exportadoras cierra las sesiones abiertas.
            $revocar = $antes !== $despues || filled($datos['password'] ?? null);
            if ($revocar) {
                $usuario->tokens()->delete();
            }

            return $revocar;
        }, attempts: 3);

        return response()->json(['usuario' => $this->usuario($usuario->fresh()->load('clientesPortal')), 'sesiones_revocadas' => $revocadas]);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?User $usuario): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password' => [$usuario ? 'nullable' : 'required', 'string', 'min:10', 'regex:/^(?=.*\p{L})(?=.*\p{N})/u'],
            'activo' => [$usuario ? 'required' : 'sometimes', 'boolean'],
            'clientes' => ['required', 'array', 'min:1', 'max:20'],
            'clientes.*' => ['uuid', 'distinct', Rule::exists('clientes', 'id')->where('activo', true)],
        ], ['password.regex' => 'La contraseña debe tener letras y números.']);
    }

    /** @param list<string> $clientes */
    private function sincronizar(User $usuario, array $clientes): void
    {
        $actuales = $usuario->clientesPortal()->pluck('clientes.id')->all();
        $usuario->clientesPortal()->detach(array_diff($actuales, $clientes));
        foreach (array_diff($clientes, $actuales) as $id) {
            $usuario->clientesPortal()->attach($id, ['created_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    private function usuario(User $usuario): array
    {
        return ['id' => $usuario->id, 'nombre' => $usuario->name, 'email' => $usuario->email, 'activo' => (bool) $usuario->activo,
            'debe_cambiar_password' => (bool) $usuario->debe_cambiar_password,
            'clientes' => $usuario->clientesPortal->map(fn (Cliente $c): array => $c->only(['id', 'codigo', 'nombre']))->values()];
    }
}
