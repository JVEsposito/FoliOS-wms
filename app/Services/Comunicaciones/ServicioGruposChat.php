<?php

namespace App\Services\Comunicaciones;

use App\Enums\RolUsuario;
use App\Models\ConversacionChat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioGruposChat
{
    public function __construct(private readonly ServicioChatInterno $chat) {}

    public function crear(User $usuario, array $datos): ConversacionChat
    {
        return DB::transaction(function () use ($usuario, $datos): ConversacionChat {
            $clave = "grupo:{$usuario->id}:{$datos['operacion_id']}";
            $nuevo = DB::table('com_conversaciones')->insertOrIgnore([
                'tipo' => 'grupo', 'clave' => $clave, 'nombre' => $datos['nombre'],
                'creador_user_id' => $usuario->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $grupo = ConversacionChat::where('clave', $clave)->lockForUpdate()->firstOrFail();
            if ($nuevo) {
                if (in_array($usuario->id, array_map('intval', $datos['miembros']), true)) {
                    throw ValidationException::withMessages(['miembros' => 'Selecciona a otras personas del equipo.']);
                }
                $this->sincronizar($grupo, $datos['miembros']);
            }

            return $grupo;
        }, 3);
    }

    public function editar(User $usuario, int $id, array $datos): ConversacionChat
    {
        return DB::transaction(function () use ($usuario, $id, $datos): ConversacionChat {
            $grupo = $this->obtener($usuario, $id, true);
            abort_unless($this->puedeGestionar($grupo, $usuario), 403);
            $this->sincronizar($grupo, $datos['miembros']);
            $grupo->update(['nombre' => $datos['nombre']]);

            return $grupo;
        }, 3);
    }

    public function integrantes(User $usuario, int $id): array
    {
        $grupo = $this->obtener($usuario, $id);

        return [
            'nombre' => $grupo->nombre,
            'creador_id' => $grupo->creador_user_id,
            'puede_gestionar' => $this->puedeGestionar($grupo, $usuario),
            'miembros' => $grupo->participantes()->orderBy('name')->orderBy('users.id')
                ->get(['users.id', 'name', 'activo', 'rol', 'foto_perfil_ruta'])
                ->map(fn (User $persona) => [...$this->chat->representarPersona($persona), 'activo' => $persona->activo && ! $persona->esCliente()])->all(),
        ];
    }

    private function obtener(User $usuario, int $id, bool $bloquear = false): ConversacionChat
    {
        $grupo = $this->chat->obtener($usuario, $id, $bloquear);
        abort_unless($grupo->tipo === 'grupo', 404);

        return $grupo;
    }

    private function puedeGestionar(ConversacionChat $grupo, User $usuario): bool
    {
        return $grupo->creador_user_id === $usuario->id || $usuario->can('moderar-chat-interno');
    }

    private function sincronizar(ConversacionChat $grupo, array $miembros): void
    {
        $ids = array_values(array_unique([$grupo->creador_user_id, ...array_map('intval', $miembros)]));
        $actuales = $grupo->participantes()->pluck('users.id')->all();
        $nuevos = array_diff($ids, $actuales);
        if (User::whereKey($nuevos)->where('activo', true)->where('rol', '!=', RolUsuario::Cliente->value)->count() !== count($nuevos)) {
            throw ValidationException::withMessages(['miembros' => 'Selecciona solo personas activas del equipo.']);
        }

        $grupo->participantes()->sync($ids);
    }
}
