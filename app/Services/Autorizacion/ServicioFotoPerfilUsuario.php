<?php

namespace App\Services\Autorizacion;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ServicioFotoPerfilUsuario
{
    public function guardar(User $usuario, UploadedFile $archivo): User
    {
        $jpeg = app(ProcesadorFotoPerfil::class)->procesar($archivo);
        $ruta = 'perfiles-usuarios/'.$usuario->id.'/'.Str::uuid().'.jpg';
        try {
            if (! Storage::disk('local')->put($ruta, $jpeg)) {
                throw new RuntimeException('No se pudo guardar la foto de perfil.');
            }

            return $this->cambiar($usuario, $ruta);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($ruta);
            throw $error;
        }
    }

    public function quitar(User $usuario): User
    {
        return $this->cambiar($usuario, null);
    }

    private function cambiar(User $usuario, ?string $ruta): User
    {
        return DB::transaction(function () use ($usuario, $ruta): User {
            $actual = User::query()->whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            abort_unless($actual->activo, 403);
            $anterior = $actual->foto_perfil_ruta;
            $actual->forceFill(['foto_perfil_ruta' => $ruta])->save();
            if ($anterior) {
                DB::afterCommit(function () use ($anterior): void {
                    try {
                        Storage::disk('local')->delete($anterior);
                    } catch (Throwable $error) {
                        // La foto nueva ya quedó confirmada: no se borra por un fallo de limpieza.
                        report($error);
                    }
                });
            }

            return $actual;
        }, 3);
    }
}
