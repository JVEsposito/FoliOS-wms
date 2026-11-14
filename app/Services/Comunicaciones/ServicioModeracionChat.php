<?php

namespace App\Services\Comunicaciones;

use App\Enums\RolUsuario;
use App\Models\ConversacionChat;
use App\Models\MensajeChat;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServicioModeracionChat
{
    public function __construct(private readonly ServicioChatInterno $chat) {}

    public function eliminar(User $moderador, int $conversacionId, int $mensajeId): MensajeChat
    {
        return DB::transaction(function () use ($moderador, $conversacionId, $mensajeId): MensajeChat {
            $conversacion = $this->conversacionModerable($moderador, $conversacionId);
            $mensaje = $conversacion->mensajes()->findOrFail($mensajeId);
            if (! $mensaje->eliminado_at) {
                $revision = $conversacion->revision_moderacion + 1;
                $conversacion->update(['revision_moderacion' => $revision]);
                $mensaje->update(['eliminado_por_user_id' => $moderador->id, 'eliminado_at' => now(), 'revision_eliminacion' => $revision]);
            }

            return $mensaje;
        }, 3);
    }

    public function silenciar(User $moderador, int $conversacionId, int $persona, int $minutos): void
    {
        DB::transaction(function () use ($moderador, $conversacionId, $persona, $minutos): void {
            $conversacion = $this->conversacionModerable($moderador, $conversacionId);
            abort_if($moderador->id === $persona, 422, 'Selecciona a otra persona para silenciar.');
            $destino = $conversacion->tipo === 'grupo' ? $conversacion->participantes() : User::query();
            $destino->where('users.id', $persona)->where('users.activo', true)
                ->where('users.rol', '!=', RolUsuario::Cliente->value)->firstOrFail();
            $this->revocarVigente($moderador, $conversacionId, $persona);
            DB::table('com_silencios_chat')->insert([
                'conversacion_id' => $conversacionId, 'user_id' => $persona, 'moderador_user_id' => $moderador->id,
                'created_at' => now(), 'hasta' => now()->addMinutes($minutos),
            ]);
        }, 3);
    }

    public function revocar(User $moderador, int $conversacionId, int $persona): void
    {
        DB::transaction(function () use ($moderador, $conversacionId, $persona): void {
            $this->conversacionModerable($moderador, $conversacionId);
            $this->revocarVigente($moderador, $conversacionId, $persona);
        }, 3);
    }

    private function conversacionModerable(User $moderador, int $id): ConversacionChat
    {
        $conversacion = $this->chat->obtener($moderador, $id, true);
        abort_if($conversacion->tipo === 'privado', 403, 'La moderación está disponible en el chat global y los grupos.');

        return $conversacion;
    }

    private function revocarVigente(User $moderador, int $conversacion, int $persona): void
    {
        DB::table('com_silencios_chat')->where('conversacion_id', $conversacion)->where('user_id', $persona)
            ->whereNull('revocado_at')->where('hasta', '>', now())
            ->update(['revocado_at' => now(), 'revocado_por_user_id' => $moderador->id]);
    }
}
