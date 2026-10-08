<?php

namespace App\Services\Materiales;

use App\Exceptions\ConflictoOperacion;
use App\Models\FotoItemMaterial;
use App\Models\ItemMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ServicioFotosItemMaterial
{
    public function subir(ItemMaterial $item, array $archivos, User $usuario, ?int $version): void
    {
        $preparadas = [];
        $rutas = [];
        try {
            foreach ($archivos as $archivo) {
                $mini = app(GeneradorMiniaturaItemMaterial::class)->generar($archivo);
                $id = (string) Str::uuid();
                $base = 'fotos-items-materiales/'.$id;
                $ruta = $base.'.'.$archivo->extension();
                $rutaMini = $base.'-mini.jpg';
                $rutas = [...$rutas, $ruta, $rutaMini];
                if (! Storage::disk('local')->putFileAs('fotos-items-materiales', $archivo, basename($ruta)) || ! Storage::disk('local')->put($rutaMini, $mini['jpeg'])) {
                    throw new RuntimeException('No se pudieron guardar las fotografías.');
                }
                $preparadas[] = ['id' => $id, 'archivo_origen_id' => $id, 'ruta' => $ruta, 'ruta_miniatura' => $rutaMini,
                    'mime' => $archivo->getMimeType(), 'tamano_bytes' => $archivo->getSize(), 'ancho' => $mini['ancho'], 'alto' => $mini['alto']];
            }
            DB::transaction(function () use ($item, $preparadas, $usuario, $version) {
                $item = $this->bloquearItem($item, $version);
                $fotos = $item->fotos()->lockForUpdate()->get();
                if ($fotos->count() + count($preparadas) > 10) {
                    throw ValidationException::withMessages(['fotografias' => 'Cada ítem admite como máximo diez fotos.']);
                }
                $orden = (int) $fotos->max('orden');
                foreach ($preparadas as $i => $foto) {
                    FotoItemMaterial::create([...$foto, 'item_material_id' => $item->id, 'orden' => ++$orden,
                        'principal' => $fotos->isEmpty() && $i === 0, 'subida_por_user_id' => $usuario->id, 'subida_at' => now()]);
                }
                $this->modificado($item, $usuario);
            }, 3);
        } catch (\Throwable $error) {
            if (! FotoItemMaterial::withTrashed()->whereIn('id', array_column($preparadas, 'id'))->exists()) {
                Storage::disk('local')->delete($rutas);
            }
            throw $error;
        }
    }

    public function ordenar(ItemMaterial $item, array $ids, string $principal, User $usuario, ?int $version): void
    {
        DB::transaction(function () use ($item, $ids, $principal, $usuario, $version) {
            $item = $this->bloquearItem($item, $version);
            $fotos = $item->fotos()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $esperados = $fotos->keys()->sort()->values()->all();
            $recibidos = collect($ids)->sort()->values()->all();
            if ($esperados !== $recibidos || ! $fotos->has($principal)) {
                throw ValidationException::withMessages(['orden' => 'Incluye todas las fotos activas del ítem, sin repetirlas.']);
            }
            $item->fotos()->update(['principal' => false]);
            foreach ($ids as $i => $id) {
                $fotos[$id]->update(['orden' => $i + 1, 'principal' => $id === $principal]);
            }
            $this->modificado($item, $usuario);
        }, 3);
    }

    public function eliminar(ItemMaterial $item, FotoItemMaterial $foto, User $usuario, ?int $version): void
    {
        DB::transaction(function () use ($item, $foto, $usuario, $version) {
            $item = $this->bloquearItem($item, $version);
            $fotos = $item->fotos()->orderBy('orden')->orderBy('id')->lockForUpdate()->get();
            $foto = $fotos->firstWhere('id', $foto->id);
            abort_unless($foto, 404);
            $eraPrincipal = $foto->principal;
            $foto->update(['principal' => false, 'eliminada_por_user_id' => $usuario->id]);
            $foto->delete();
            if ($eraPrincipal && ($siguiente = $fotos->first(fn ($f) => $f->id !== $foto->id))) {
                $siguiente->update(['principal' => true]);
            }
            $this->modificado($item, $usuario);
            DB::afterCommit(fn () => $this->limpiarArchivo($foto->archivo_origen_id));
        }, 3);
    }

    public function copiar(ItemMaterial $origen, ItemMaterial $destino): void
    {
        ItemMaterial::whereKey($origen->id)->lockForUpdate()->firstOrFail();
        foreach ($origen->fotos()->orderBy('id')->lockForUpdate()->get() as $foto) {
            // El registro original serializa la copia con la limpieza del archivo.
            FotoItemMaterial::withTrashed()->whereKey($foto->archivo_origen_id)->lockForUpdate()->firstOrFail();
            FotoItemMaterial::create([...$foto->only(['archivo_origen_id', 'ruta', 'ruta_miniatura', 'mime', 'tamano_bytes', 'ancho', 'alto', 'orden', 'principal', 'subida_por_user_id', 'subida_at']), 'item_material_id' => $destino->id]);
        }
    }

    public function limpiarArchivo(string $originalId): void
    {
        DB::transaction(function () use ($originalId) {
            $original = FotoItemMaterial::withTrashed()->whereKey($originalId)->lockForUpdate()->firstOrFail();
            if (! FotoItemMaterial::where('archivo_origen_id', $originalId)->exists()) {
                Storage::disk('local')->delete([$original->ruta, $original->ruta_miniatura]);
            }
        }, 3);
    }

    private function bloquearItem(ItemMaterial $item, ?int $version): ItemMaterial
    {
        $item = ItemMaterial::whereKey($item->id)->lockForUpdate()->firstOrFail();
        if ($version !== null && (int) $item->fotos_version !== $version) {
            throw new ConflictoOperacion('Las fotos cambiaron. Actualiza la ficha antes de guardar.');
        }

        return $item;
    }

    private function modificado(ItemMaterial $item, User $usuario): void
    {
        $item->forceFill(['fotos_version' => (int) $item->fotos_version + 1, 'actualizado_por_user_id' => $usuario->id])->save();
    }
}
