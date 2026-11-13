<?php

namespace App\Services\Comunicaciones;

use App\Models\StickerChat;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ServicioStickersChat
{
    public function subir(User $usuario, UploadedFile $archivo, string $nombre, string $operacion): StickerChat
    {
        $hash = hash_file('sha256', $archivo->getRealPath());
        $mime = $archivo->getMimeType();
        $extension = match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => null
        };
        abort_unless($extension, 422, 'Selecciona un sticker PNG, WebP o GIF.');
        $ruta = 'comunicaciones/stickers/'.Str::uuid().'.'.$extension;
        try {
            if (! Storage::disk('local')->putFileAs('comunicaciones/stickers', $archivo, basename($ruta))) {
                throw new RuntimeException('No se pudo guardar el sticker.');
            }
            $sticker = DB::transaction(function () use ($usuario, $nombre, $operacion, $hash, $mime, $ruta): StickerChat {
                // Serializa el cupo y los reintentos del mismo usuario sin bloquear otros chats.
                $actual = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
                abort_unless($actual->activo && ! $actual->esCliente(), 403);
                $existente = StickerChat::where('user_id', $usuario->id)->where('operacion_id', $operacion)->first();
                if ($existente) {
                    abort_unless($existente->sha256 === $hash && $existente->nombre === $nombre, 409, 'Este identificador ya corresponde a otro sticker.');

                    return $existente;
                }
                if (StickerChat::where('user_id', $usuario->id)->count() >= 200) {
                    throw ValidationException::withMessages(['archivo' => 'Se alcanzó el límite de 200 stickers por usuario.']);
                }

                return StickerChat::create(['user_id' => $usuario->id, 'operacion_id' => $operacion, 'nombre' => $nombre,
                    'ruta' => $ruta, 'mime' => $mime, 'sha256' => $hash, 'activo' => true]);
            }, 3);
            if ($sticker->ruta !== $ruta) {
                Storage::disk('local')->delete($ruta);
            }

            return $sticker;
        } catch (Throwable $error) {
            if (! StickerChat::where('ruta', $ruta)->exists()) {
                Storage::disk('local')->delete($ruta);
            }
            throw $error;
        }
    }

    public static function representar(StickerChat $sticker, User $usuario): array
    {
        return ['id' => $sticker->id, 'nombre' => $sticker->nombre, 'activo' => $sticker->activo,
            'puede_ocultar' => $sticker->user_id === $usuario->id,
            'archivo_url' => '/api/comunicaciones/stickers/'.$sticker->id.'/archivo'];
    }
}
