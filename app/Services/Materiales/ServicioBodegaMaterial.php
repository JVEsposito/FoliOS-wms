<?php

namespace App\Services\Materiales;

use App\Exceptions\ConflictoOperacion;
use App\Models\AlmacenMaterial;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicioBodegaMaterial
{
    public function guardar(?string $id, array $datos, User $actor): array
    {
        $hash = hash('sha256', json_encode([$id, $datos], JSON_THROW_ON_ERROR));
        $repetir = function ($evento) use ($hash, $actor) {
            if ($evento->payload_hash !== $hash || (int) $evento->user_id !== (int) $actor->id) {
                throw new ConflictoOperacion('Este UUID ya se utilizó con otros datos o usuario.');
            }

            return json_decode($evento->resultado, true, flags: JSON_THROW_ON_ERROR);
        };
        try {
            return DB::transaction(function () use ($id, $datos, $actor, $hash, $repetir) {
                $evento = DB::table('eventos_bodegas_materiales')->where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
                if ($evento) {
                    return $repetir($evento);
                }
                $antes = $id ? DB::table('destinos_materiales')->where('id', $id)->lockForUpdate()->first() : null;
                abort_if($id && (! $antes || $antes->tipo !== 'fisica'), 404);
                if ((int) ($antes->version_bodega ?? 0) !== (int) $datos['version']) {
                    throw new ConflictoOperacion('La bodega cambió. Actualiza los datos antes de guardar.');
                }
                if ($antes && $antes->codigo !== $datos['codigo']) {
                    throw ValidationException::withMessages(['codigo' => 'El código de una bodega existente se conserva para su trazabilidad.']);
                }
                if (! $datos['activo'] && (! $antes || $antes->codigo === AlmacenMaterial::CODIGO_BODEGA_CENTRAL || $this->tienePendientes($id))) {
                    throw ValidationException::withMessages(['activo' => 'Solo se desactivan bodegas existentes, sin stock, reservas ni documentos pendientes. Bodega Central debe permanecer activa.']);
                }
                $clave = $id ?? (string) Str::uuid();
                $r = ['codigo' => $datos['codigo'], 'nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null,
                    'activo' => $datos['activo'], 'version_bodega' => (int) ($antes->version_bodega ?? 0) + 1,
                    'actualizado_por_user_id' => $actor->id, 'updated_at' => now()];
                if ($antes) {
                    DB::table('destinos_materiales')->where('id', $clave)->update($r);
                } else {
                    DB::table('destinos_materiales')->insert([...$r, 'id' => $clave, 'tipo' => 'fisica', 'centro_costo' => $datos['codigo'],
                        'requiere_ubicacion_fisica' => true, 'origen_sistema' => 'estiba_wms', 'creado_por_user_id' => $actor->id, 'created_at' => now()]);
                }
                $resultado = (array) DB::table('destinos_materiales')->where('id', $clave)->first();
                DB::table('eventos_bodegas_materiales')->insert(['id' => (string) Str::uuid(), 'operacion_id' => $datos['operacion_id'],
                    'almacen_material_id' => $clave, 'user_id' => $actor->id, 'usuario' => $actor->name, 'payload_hash' => $hash,
                    'anterior' => $antes ? json_encode($antes, JSON_THROW_ON_ERROR) : null,
                    'resultado' => json_encode($resultado, JSON_THROW_ON_ERROR), 'ocurrido_at' => now()->format('Y-m-d H:i:s.u')]);

                return $resultado;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            $evento = DB::table('eventos_bodegas_materiales')->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                return $repetir($evento);
            }
            throw ValidationException::withMessages(['codigo' => 'El código ya pertenece a una bodega o destino existente.']);
        }
    }

    private function tienePendientes(string $id): bool
    {
        return DB::table('saldos_materiales_almacenes')->where('almacen_material_id', $id)
            ->where(fn ($q) => $q->where('cantidad_actual', '>', 0)->orWhere('cantidad_reservada', '>', 0))->exists()
            || DB::table('despachos_materiales')->where('almacen_origen_id', $id)->whereIn('estado', ['pendiente', 'parcial'])->exists()
            || DB::table('ordenes_transformacion_materiales')->where('almacen_origen_id', $id)->whereNotIn('estado', ['cerrada', 'cancelada'])->exists();
    }
}
