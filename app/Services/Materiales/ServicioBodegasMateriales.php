<?php

namespace App\Services\Materiales;

use App\Enums\EstadoDespachoMaterial;
use App\Enums\TipoAlmacenMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\AlmacenMaterial;
use App\Models\DespachoMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ServicioBodegasMateriales
{
    public function catalogo(): array
    {
        return AlmacenMaterial::where('tipo', TipoAlmacenMaterial::Fisica->value)
            ->withSum('saldos as existencia', 'cantidad_actual')->withSum('saldos as reservada', 'cantidad_reservada')
            ->orderByDesc('activo')->orderBy('nombre')->get()->map(fn ($a) => [
                ...$this->presentar($a), 'con_existencias' => (float) $a->existencia > 0 || (float) $a->reservada > 0,
            ])->all();
    }

    public function presentar(AlmacenMaterial $a): array
    {
        return $a->only(['id', 'codigo', 'nombre', 'descripcion', 'activo', 'requiere_ubicacion_fisica'])
            + ['version' => (int) $a->version_bodega, 'central' => $a->codigo === AlmacenMaterial::CODIGO_BODEGA_CENTRAL];
    }

    public function guardar(?string $id, array $d, User $actor): array
    {
        Gate::forUser($actor)->authorize('administrar-bodegas-materiales');
        ksort($d);
        $hash = hash('sha256', json_encode([$id, $d], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($id, $d, $actor, $hash) {
                $previo = DB::table('auditoria_bodegas_materiales')->where('operacion_id', $d['operacion_id'])->first();
                if ($previo) {
                    return $this->repetir($previo, $actor, $hash);
                }
                $a = $id ? AlmacenMaterial::lockForUpdate()->findOrFail($id) : new AlmacenMaterial;
                if ($id && $a->tipo !== TipoAlmacenMaterial::Fisica) {
                    throw new DomainException('Selecciona una bodega física. Los centros de costo se administran por separado.');
                }
                if ((int) ($id ? $a->version_bodega : 0) !== (int) $d['version']) {
                    throw new ConflictoOperacion('La bodega cambió. Actualiza antes de guardar.');
                }
                $antes = $id ? $this->presentar($a) : null;
                if ($id && $d['codigo'] !== $a->codigo) {
                    throw new DomainException('El código de una bodega existente se conserva para su trazabilidad.');
                }
                $modo = (bool) $d['requiere_ubicacion_fisica'];
                if ($a->codigo === AlmacenMaterial::CODIGO_BODEGA_CENTRAL && (! $d['activo'] || ! $modo)) {
                    throw new DomainException('Bodega Central conserva su activación y su operación por plano.');
                }
                if (! $id && $d['codigo'] === AlmacenMaterial::CODIGO_BODEGA_CENTRAL) {
                    throw new DomainException('El código de Bodega Central está reservado.');
                }
                if ($id && (! $d['activo'] || $modo !== $a->requiere_ubicacion_fisica)) {
                    $ocupada = SaldoMaterialAlmacen::where('almacen_material_id', $id)->where(fn ($q) => $q->where('cantidad_actual', '>', 0)->orWhere('cantidad_reservada', '>', 0))->exists();
                    $ordenes = DespachoMaterial::where('almacen_origen_id', $id)->whereIn('estado', [EstadoDespachoMaterial::Pendiente->value, EstadoDespachoMaterial::Parcial->value])->exists();
                    if ($ocupada || $ordenes) {
                        throw new DomainException('No se desactiva ni cambia el modo de una bodega con existencias, reservas u órdenes pendientes.');
                    }
                }
                if (AlmacenMaterial::where('codigo', $d['codigo'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    throw new DomainException('El código de bodega ya existe.');
                }
                $a->fill(['codigo' => $d['codigo'], 'nombre' => $d['nombre'], 'descripcion' => $d['descripcion'] ?? null,
                    'activo' => $d['activo'], 'requiere_ubicacion_fisica' => $modo, 'tipo' => TipoAlmacenMaterial::Fisica,
                    'actualizado_por_user_id' => $actor->id, 'version_bodega' => ($id ? $a->version_bodega : 0) + 1]);
                if (! $id) {
                    $a->fill(['centro_costo' => $d['codigo'], 'origen_sistema' => 'manual', 'creado_por_user_id' => $actor->id]);
                }
                $a->save();
                $despues = $this->presentar($a->refresh());
                DB::table('auditoria_bodegas_materiales')->insert(['id' => (string) Str::uuid(), 'operacion_id' => $d['operacion_id'],
                    'almacen_material_id' => $a->id, 'user_id' => $actor->id, 'payload_hash' => $hash,
                    'antes' => $antes ? json_encode($antes, JSON_THROW_ON_ERROR) : null, 'despues' => json_encode($despues, JSON_THROW_ON_ERROR),
                    'motivo' => $d['motivo'], 'ocurrido_at' => now()]);

                return $despues;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $previo = DB::table('auditoria_bodegas_materiales')->where('operacion_id', $d['operacion_id'])->first();
            if ($previo) {
                return $this->repetir($previo, $actor, $hash);
            }
            throw new ConflictoOperacion('El código de bodega fue utilizado por otra operación. Actualiza antes de guardar.', previous: $e);
        }
    }

    private function repetir(object $evento, User $actor, string $hash): array
    {
        if ((int) $evento->user_id !== $actor->id || ! hash_equals($evento->payload_hash, $hash)) {
            throw new ConflictoOperacion('El UUID ya se utilizó con otros datos o responsable.');
        }

        return json_decode($evento->despues, true, flags: JSON_THROW_ON_ERROR);
    }
}
