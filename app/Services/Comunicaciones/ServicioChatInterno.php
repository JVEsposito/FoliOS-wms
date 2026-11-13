<?php

namespace App\Services\Comunicaciones;

use App\Enums\RolUsuario;
use App\Models\ConversacionChat;
use App\Models\MensajeChat;
use App\Models\StickerChat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioChatInterno
{
    public function conversaciones(User $usuario): Builder
    {
        return ConversacionChat::query()->leftJoin('com_participantes as mi_lectura', function ($join) use ($usuario): void {
            $join->on('mi_lectura.conversacion_id', '=', 'com_conversaciones.id')->where('mi_lectura.user_id', $usuario->id);
        })->where(fn ($q) => $q->where('tipo', 'global')->orWhereNotNull('mi_lectura.user_id'))
            ->select('com_conversaciones.*');
    }

    public function sinLeer(Builder $consulta, User $usuario): Builder
    {
        return $consulta->where('remitente_user_id', '!=', $usuario->id)
            ->where('com_mensajes.created_at', '>=', $usuario->created_at)
            ->whereColumn('com_mensajes.id', '>', DB::raw('COALESCE(mi_lectura.ultimo_leido_id, 0)'));
    }

    public function listar(User $usuario, int $pagina): array
    {
        $lista = $this->conversaciones($usuario)
            ->with('ultimoMensaje')
            ->withExists(['mensajes as tiene_no_leidos' => fn (Builder $q) => $this->sinLeer($q, $usuario)])
            ->orderByRaw("CASE WHEN tipo = 'global' THEN 0 ELSE 1 END")
            ->orderByDesc('ultimo_mensaje_id')->orderByDesc('com_conversaciones.id')->paginate(30, ['*'], 'pagina', $pagina);
        $lista->getCollection()->where('tipo', 'privado')->load('participantes:id,name,activo,rol');

        return ['data' => $lista->getCollection()->map(fn ($c) => $this->representarConversacion($c, $usuario))->all(),
            'pagina' => $lista->currentPage(), 'ultima_pagina' => $lista->lastPage()];
    }

    public function abrirPrivado(User $usuario, int $destinatario): ConversacionChat
    {
        $this->destinatario($usuario, $destinatario);
        $ids = [$usuario->id, $destinatario];
        sort($ids, SORT_NUMERIC);

        return DB::transaction(function () use ($ids): ConversacionChat {
            $clave = 'privado:'.implode(':', $ids);
            DB::table('com_conversaciones')->insertOrIgnore(['tipo' => 'privado', 'clave' => $clave, 'created_at' => now(), 'updated_at' => now()]);
            $conversacion = ConversacionChat::where('clave', $clave)->lockForUpdate()->firstOrFail();
            foreach ($ids as $id) {
                DB::table('com_participantes')->insertOrIgnore(['conversacion_id' => $conversacion->id, 'user_id' => $id]);
            }

            return $conversacion->load('participantes:id,name,activo,rol');
        }, 3);
    }

    public function obtener(User $usuario, int $id, bool $bloquear = false): ConversacionChat
    {
        $consulta = $this->conversaciones($usuario)->where('com_conversaciones.id', $id);
        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->firstOrFail();
    }

    public function mensajes(User $usuario, int $id, ?int $despues, ?int $antes, int $limite): array
    {
        $conversacion = $this->obtener($usuario, $id);
        if ($conversacion->tipo === 'privado') {
            $conversacion->load('participantes:id,name,activo,rol');
        }
        $consulta = MensajeChat::where('conversacion_id', $id)->with(['remitente:id,name', 'sticker']);
        if ($despues !== null) {
            $consulta->where('id', '>', $despues)->orderBy('id');
        } else {
            if ($antes !== null) {
                $consulta->where('id', '<', $antes);
            }
            $consulta->orderByDesc('id');
        }
        $filas = $consulta->limit($limite + 1)->get();
        $hayMas = $filas->count() > $limite;
        $filas = $filas->take($limite)->sortBy('id')->values();

        return ['conversacion' => $this->representarConversacion($conversacion, $usuario),
            'data' => $filas->map(fn ($m) => $this->representarMensaje($m, $usuario))->all(),
            'hay_mas_anteriores' => $despues === null && $hayMas, 'hay_mas_nuevos' => $despues !== null && $hayMas];
    }

    public function enviar(User $usuario, int $id, array $datos): MensajeChat
    {
        $texto = trim((string) ($datos['texto'] ?? ''));
        $texto = $texto === '' ? null : $texto;
        $sticker = isset($datos['sticker_id']) ? (int) $datos['sticker_id'] : null;
        if ($texto === null && $sticker === null) {
            throw ValidationException::withMessages(['texto' => 'Escribe un mensaje o selecciona un sticker.']);
        }
        try {
            return DB::transaction(function () use ($usuario, $id, $datos, $texto, $sticker): MensajeChat {
                // El bloqueo por conversación ordena los commits: un cursor no salta mensajes todavía sin confirmar.
                $conversacion = $this->obtener($usuario, $id, true);
                $existente = MensajeChat::where('remitente_user_id', $usuario->id)->where('operacion_id', $datos['operacion_id'])->first();
                if ($existente) {
                    return $this->reintento($existente, $id, $texto, $sticker);
                }
                if ($conversacion->tipo === 'privado') {
                    $otro = $conversacion->participantes()->where('users.id', '!=', $usuario->id)->firstOrFail();
                    $this->destinatario($usuario, $otro->id);
                }
                if ($sticker !== null && ! StickerChat::whereKey($sticker)->where('activo', true)->exists()) {
                    throw ValidationException::withMessages(['sticker_id' => 'Este sticker ya no está disponible en la galería.']);
                }
                $mensaje = MensajeChat::create(['conversacion_id' => $id, 'remitente_user_id' => $usuario->id,
                    'operacion_id' => $datos['operacion_id'], 'texto' => $texto, 'sticker_id' => $sticker]);
                $conversacion->update(['ultimo_mensaje_id' => $mensaje->id]);

                return $mensaje;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            $this->obtener($usuario, $id);
            $existente = MensajeChat::where('remitente_user_id', $usuario->id)->where('operacion_id', $datos['operacion_id'])->first();
            if (! $existente) {
                throw $error;
            }

            return $this->reintento($existente, $id, $texto, $sticker);
        }
    }

    public function leer(User $usuario, int $id, int $hasta): void
    {
        DB::transaction(function () use ($usuario, $id, $hasta): void {
            $this->obtener($usuario, $id, true);
            abort_unless($hasta === 0 || MensajeChat::where('conversacion_id', $id)->whereKey($hasta)->exists(), 422, 'El mensaje leído no pertenece a esta conversación.');
            DB::table('com_participantes')->insertOrIgnore(['conversacion_id' => $id, 'user_id' => $usuario->id]);
            $fila = DB::table('com_participantes')->where('conversacion_id', $id)->where('user_id', $usuario->id)->lockForUpdate()->first();
            DB::table('com_participantes')->where('conversacion_id', $id)->where('user_id', $usuario->id)
                ->update(['ultimo_leido_id' => max($hasta, $fila->ultimo_leido_id), 'leido_at' => now()]);
        }, 3);
    }

    public function representarConversacion(ConversacionChat $conversacion, User $usuario): array
    {
        $otro = $conversacion->tipo === 'privado' ? $conversacion->participantes->firstWhere('id', '!=', $usuario->id) : null;

        return ['id' => $conversacion->id, 'tipo' => $conversacion->tipo,
            'nombre' => $conversacion->tipo === 'global' ? 'Chat global' : ($otro?->name ?? 'Usuario'),
            'puede_enviar' => $conversacion->tipo === 'global' || ($otro?->activo && ! $otro->esCliente()),
            'tiene_no_leidos' => (bool) ($conversacion->tiene_no_leidos ?? false),
            'ultimo_mensaje_id' => (int) $conversacion->ultimo_mensaje_id,
            'extracto' => $conversacion->ultimoMensaje ? mb_substr($conversacion->ultimoMensaje->texto ?? 'Sticker', 0, 100) : null];
    }

    public function representarMensaje(MensajeChat $mensaje, User $usuario): array
    {
        $mensaje->loadMissing(['remitente:id,name', 'sticker']);

        return ['id' => $mensaje->id, 'conversacion_id' => $mensaje->conversacion_id,
            'remitente' => ['id' => $mensaje->remitente_user_id, 'nombre' => $mensaje->remitente->name],
            'texto' => $mensaje->texto, 'sticker' => $mensaje->sticker ? ServicioStickersChat::representar($mensaje->sticker, $usuario) : null,
            'creado_at' => $mensaje->created_at->toAtomString(),
            'operacion_id' => $mensaje->remitente_user_id === $usuario->id ? $mensaje->operacion_id : null];
    }

    private function reintento(MensajeChat $mensaje, int $id, ?string $texto, ?int $sticker): MensajeChat
    {
        abort_unless($mensaje->conversacion_id === $id && $mensaje->texto === $texto && $mensaje->sticker_id === $sticker, 409, 'Este identificador ya se usó para otro mensaje.');

        return $mensaje;
    }

    private function destinatario(User $usuario, int $id): void
    {
        if ($id === $usuario->id || ! User::whereKey($id)->where('activo', true)->where('rol', '!=', RolUsuario::Cliente->value)->exists()) {
            throw ValidationException::withMessages(['usuario_id' => 'Selecciona otra persona activa del equipo.']);
        }
    }
}
