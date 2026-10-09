<?php

namespace App\Services\Materiales;

use App\Enums\EstadoRecepcionMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\FotoDocumentoRecepcionRequerida;
use App\Models\EliminacionRecepcionMaterial;
use App\Models\FotoRecepcionMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ServicioFotosRecepcionMaterial
{
    public function subir(RecepcionMaterial $recepcion, UploadedFile $archivo, string $tipo, string $operacion, User $usuario): array
    {
        $sha = hash_file('sha256', $archivo->getRealPath());
        $id = (string) Str::uuid();
        $mime = $archivo->getMimeType();
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $ext || ! in_array($tipo, ['documento', 'referencial'], true) || $archivo->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['archivo' => 'Usa JPG, PNG o WebP de hasta 5 MiB.']);
        }
        $mini = app(GeneradorMiniaturaItemMaterial::class)->generar($archivo);
        $base = 'materiales/recepciones/'.$recepcion->id;
        $original = $base.'/'.$id.'.'.$ext;
        $miniatura = $base.'/'.$id.'-mini.jpg';
        $guardada = false;
        try {
            if (! Storage::disk('local')->putFileAs($base, $archivo, basename($original)) || ! Storage::disk('local')->put($miniatura, $mini['jpeg'])) {
                throw new RuntimeException('No se pudieron guardar las fotos de recepción.');
            }
            $resultado = DB::transaction(function () use ($recepcion, $tipo, $operacion, $usuario, $sha, $id, $mime, $archivo, $mini, $original, $miniatura) {
                $recepcion = RecepcionMaterial::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();
                $existente = FotoRecepcionMaterial::withTrashed()->where('operacion_id', $operacion)->first();
                if ($existente) {
                    if ($existente->recepcion_material_id !== $recepcion->id || $existente->tipo !== $tipo || ! hash_equals($existente->sha256, $sha)) {
                        throw new ConflictoOperacion('El operacion_id ya fue utilizado con otra foto.');
                    }

                    return [$existente, false];
                }
                $this->editable($recepcion);
                app(GuardiaTemporadaActiva::class)->asegurar(new FotoRecepcionMaterial(['recepcion_material_id' => $recepcion->id]));
                $fotos = $recepcion->fotos()->where('tipo', $tipo)->lockForUpdate()->get();
                if ($fotos->count() >= 5) {
                    throw ValidationException::withMessages(['archivo' => 'Se admiten hasta cinco fotos de cada tipo por recepción.']);
                }
                $orden = (int) FotoRecepcionMaterial::withTrashed()->where('recepcion_material_id', $recepcion->id)->where('tipo', $tipo)->max('orden') + 1;
                $foto = FotoRecepcionMaterial::create([
                    'id' => $id, 'recepcion_material_id' => $recepcion->id, 'tipo' => $tipo, 'orden' => $orden,
                    'ruta_original' => $original, 'ruta_miniatura' => $miniatura, 'mime' => $mime,
                    'bytes' => $archivo->getSize(), 'ancho' => $mini['ancho'], 'alto' => $mini['alto'],
                    'sha256' => $sha, 'operacion_id' => $operacion, 'subida_por_user_id' => $usuario->id,
                ]);

                return [$foto, true];
            }, 3);
            $guardada = $resultado[1];

            return $resultado;
        } catch (UniqueConstraintViolationException $error) {
            $existente = FotoRecepcionMaterial::withTrashed()->where('operacion_id', $operacion)->first();
            if (! $existente) {
                throw $error;
            }
            if ($existente->recepcion_material_id !== $recepcion->id || $existente->tipo !== $tipo || ! hash_equals($existente->sha256, $sha)) {
                throw new ConflictoOperacion('El operacion_id ya fue utilizado con otra foto.');
            }

            return [$existente, false];
        } finally {
            if (! $guardada && ! FotoRecepcionMaterial::withTrashed()->whereKey($id)->exists()) {
                Storage::disk('local')->delete([$original, $miniatura]);
            }
        }
    }

    public function eliminar(RecepcionMaterial $recepcion, FotoRecepcionMaterial $foto, User $usuario, ?string $motivo): void
    {
        DB::transaction(function () use ($recepcion, $foto, $usuario, $motivo) {
            $recepcion = RecepcionMaterial::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();
            $this->editable($recepcion);
            $foto = $recepcion->fotos()->whereKey($foto->id)->lockForUpdate()->firstOrFail();
            if ($recepcion->estado === EstadoRecepcionMaterial::Confirmada) {
                Gate::forUser($usuario)->authorize('administrar-recepciones-materiales');
                if (! trim((string) $motivo)) {
                    throw ValidationException::withMessages(['motivo' => 'Indica el motivo de eliminación.']);
                }
                if ($foto->tipo === 'documento' && $recepcion->fotos()->where('tipo', 'documento')->count() <= 1) {
                    throw new FotoDocumentoRecepcionRequerida('recepcion_requiere_foto_documento');
                }
                $foto->update(['eliminada_por_user_id' => $usuario->id, 'motivo_eliminacion' => trim($motivo)]);
                $foto->delete();
            } else {
                $foto->forceDelete();
                DB::afterCommit(fn () => Storage::disk('local')->delete([$foto->ruta_original, $foto->ruta_miniatura]));
            }
        }, 3);
    }

    /** Se ejecuta dentro de la transacción administrativa, con la recepción bloqueada. */
    public function archivar(RecepcionMaterial $recepcion, EliminacionRecepcionMaterial $eliminacion): void
    {
        $fotos = FotoRecepcionMaterial::withTrashed()->where('recepcion_material_id', $recepcion->id)->orderBy('tipo')->orderBy('orden')->lockForUpdate()->get();
        $snapshot = $eliminacion->snapshot;
        $snapshot['fotos'] = $fotos->map(function ($foto) use ($eliminacion) {
            $base = 'materiales/recepciones-eliminadas/'.$eliminacion->id.'/';

            return [...$foto->only(['tipo', 'orden', 'sha256', 'bytes', 'mime', 'eliminada_at', 'eliminada_por_user_id', 'motivo_eliminacion']),
                'ruta' => $base.basename($foto->ruta_original), 'ruta_miniatura' => $base.basename($foto->ruta_miniatura),
                'ruta_origen' => $foto->ruta_original, 'ruta_miniatura_origen' => $foto->ruta_miniatura];
        })->values()->all();
        $eliminacion->update(['snapshot' => $snapshot]);
        FotoRecepcionMaterial::withTrashed()->where('recepcion_material_id', $recepcion->id)->forceDelete();
        DB::afterCommit(function () use ($snapshot, $eliminacion) {
            foreach ($snapshot['fotos'] as $foto) {
                foreach (['ruta' => 'ruta_origen', 'ruta_miniatura' => 'ruta_miniatura_origen'] as $destino => $origen) {
                    try {
                        if (! Storage::disk('local')->move($foto[$origen], $foto[$destino])) {
                            throw new RuntimeException('No se pudo mover el archivo.');
                        }
                    } catch (\Throwable $error) {
                        Log::error('No se pudo archivar la evidencia de recepción eliminada.', ['eliminacion_id' => $eliminacion->id, 'sha256' => $foto['sha256'], 'ruta' => $foto[$origen], 'error' => $error->getMessage()]);
                    }
                }
            }
        });
    }

    private function editable(RecepcionMaterial $recepcion): void
    {
        if ($recepcion->estado === EstadoRecepcionMaterial::Anulada) {
            throw ValidationException::withMessages(['recepcion' => 'Las fotos de una recepción anulada son de solo lectura.']);
        }
    }
}
