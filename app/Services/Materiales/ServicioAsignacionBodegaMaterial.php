<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Asigna custodia inicial, nunca una cámara ni espacio físico certificado. */
class ServicioAsignacionBodegaMaterial
{
    public function guardar(?string $id, array $d, User $actor): array
    {
        Gate::forUser($actor)->authorize('configurar-capacidad-materiales');
        ksort($d);
        $hash = hash('sha256', json_encode([$id, $d], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($id, $d, $actor, $hash) {
            DB::table('configuracion_asignacion_bodegas_materiales')->where('id', 1)->lockForUpdate()->firstOrFail();
            $evento = DB::table('eventos_reglas_bodegas_materiales')->where('operacion_id', $d['operacion_id'])->lockForUpdate()->first();
            if ($evento) {
                if ((int) $evento->user_id !== (int) $actor->id || ! hash_equals($evento->payload_hash, $hash)) {
                    throw new ConflictoOperacion('El UUID ya se utilizó con otros datos o responsable.');
                }

                return json_decode($evento->despues, true, flags: JSON_THROW_ON_ERROR);
            }
            $antes = $id ? DB::table('reglas_bodegas_materiales')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $antes, 404);
            if ((int) ($antes->version ?? 0) !== (int) $d['version']) {
                throw new ConflictoOperacion('La regla cambió. Actualiza antes de guardar.');
            }
            $bodega = DB::table('destinos_materiales')->where('id', $d['almacen_material_id'])->where('tipo', 'fisica')->lockForUpdate()->first();
            if (! $bodega || ($d['activo'] && ! $bodega->activo)) {
                throw ValidationException::withMessages(['almacen_material_id' => 'Selecciona una bodega física activa.']);
            }
            if ($d['activo'] && DB::table('reglas_bodegas_materiales')->where('activo', true)->where('prioridad', $d['prioridad'])->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['prioridad' => 'Cada regla activa debe tener una prioridad distinta. Un número menor se evalúa primero.']);
            }
            if ($d['activo'] && ($d['cliente_id'] ?? null) && ! DB::table('clientes')->where('id', $d['cliente_id'])->where('activo', true)->exists()) {
                throw ValidationException::withMessages(['cliente_id' => 'El cliente debe existir y estar activo.']);
            }
            $key = $id ?? (string) Str::uuid();
            $row = ['nombre' => $d['nombre'], 'almacen_material_id' => $bodega->id, 'cliente_id' => $d['cliente_id'] ?? null, 'categoria_operacional' => $d['categoria_operacional'] ?? null,
                'prioridad' => $d['prioridad'], 'activo' => $d['activo'], 'version' => (int) ($antes->version ?? 0) + 1, 'actualizado_por_user_id' => $actor->id, 'updated_at' => now()];
            if ($id) {
                DB::table('reglas_bodegas_materiales')->where('id', $id)->update($row);
            } else {
                DB::table('reglas_bodegas_materiales')->insert(['id' => $key, ...$row, 'created_at' => now()]);
            }
            $despues = (array) DB::table('reglas_bodegas_materiales')->where('id', $key)->first();
            DB::table('eventos_reglas_bodegas_materiales')->insert(['id' => (string) Str::uuid(), 'operacion_id' => $d['operacion_id'], 'regla_id' => $key, 'user_id' => $actor->id, 'usuario' => $actor->name, 'payload_hash' => $hash,
                'antes' => $antes ? json_encode($antes, JSON_THROW_ON_ERROR) : null, 'despues' => json_encode($despues, JSON_THROW_ON_ERROR), 'motivo' => $d['motivo'], 'ocurrido_at' => now()->format('Y-m-d H:i:s.u')]);

            return $despues;
        }, 3);
    }

    /** Se llama dentro de la confirmación y su misma transacción. */
    public function elegir(string $clienteId, CategoriaOperacionalMaterial $categoria, User $actor): array
    {
        DB::table('configuracion_asignacion_bodegas_materiales')->where('id', 1)->sharedLock()->firstOrFail();
        $reglas = DB::table('reglas_bodegas_materiales')->where('activo', true)
            ->where(fn ($q) => $q->whereNull('cliente_id')->orWhere('cliente_id', $clienteId))
            ->where(fn ($q) => $q->whereNull('categoria_operacional')->orWhere('categoria_operacional', $categoria->value))
            ->orderBy('prioridad')->orderBy('id')->sharedLock()->get();
        foreach ($reglas as $regla) {
            $bodega = DB::table('destinos_materiales')->where('id', $regla->almacen_material_id)->where('tipo', 'fisica')->where('activo', true)->lockForUpdate()->first();
            if ($bodega) {
                return $this->decision($bodega, $actor, $regla);
            }
        }

        return $this->decision(app(ServicioAlmacenMaterial::class)->bodegaCentral($actor, bloquear: true), $actor, null);
    }

    private function decision(object $bodega, User $actor, ?object $regla): array
    {
        return ['almacen_id' => $bodega->id, 'codigo' => $bodega->codigo, 'nombre' => $bodega->nombre,
            'origen' => $regla ? 'regla' : 'central_por_defecto', 'regla' => $regla ? ['id' => $regla->id, 'nombre' => $regla->nombre, 'version' => (int) $regla->version, 'prioridad' => (int) $regla->prioridad] : null,
            'user_id' => $actor->id, 'usuario' => $actor->name, 'asignado_at' => now()->toAtomString(),
            'ubicacion_fisica' => 'pendiente', 'capacidad' => 'pendiente_verificacion',
            'aviso' => 'Bodega asignada al ingreso. Verifica capacidad y confirma la ubicación física antes de operar.'];
    }
}
