<?php

namespace App\Services\Materiales;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\TipoAlmacenMaterial;
use App\Models\AlmacenMaterial;
use App\Models\DestinoMaterial;
use App\Models\FolioMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\UbicacionActual;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ServicioAlmacenMaterial
{
    public function bodegaCentral(?User $actor = null, bool $bloquear = false): AlmacenMaterial
    {
        $consulta = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL);

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $existente = $consulta->first();

        if ($existente) {
            return $existente;
        }

        $actor ??= User::query()->where('activo', true)->orderBy('id')->first();

        if (! $actor) {
            throw new LogicException(
                'No existe un usuario activo para crear la Bodega Central de Materiales.',
            );
        }

        DB::table('destinos_materiales')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'codigo' => AlmacenMaterial::CODIGO_BODEGA_CENTRAL,
            'nombre' => 'Bodega Central de Materiales',
            'tipo' => TipoAlmacenMaterial::Fisica->value,
            'centro_costo' => 'BODEGA',
            'requiere_ubicacion_fisica' => true,
            'descripcion' => 'Almacén físico principal del inventario de materiales.',
            'origen_sistema' => 'estiba_wms',
            'activo' => true,
            'creado_por_user_id' => $actor->id,
            'actualizado_por_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $consulta = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL);

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->firstOrFail();
    }

    public function origenDespacho(?string $id, ?User $actor = null, bool $bloquear = false): AlmacenMaterial
    {
        if (! $id) {
            $id = $this->bodegaCentral($actor, $bloquear)->id;
        }
        $q = AlmacenMaterial::whereKey($id)->where('tipo', TipoAlmacenMaterial::Fisica->value)->where('activo', true);
        if ($bloquear) {
            $q->lockForUpdate();
        }
        $almacen = $q->first();
        if (! $almacen) {
            throw new DomainException('La bodega de origen no existe, está inactiva o no es física.');
        }

        return $almacen;
    }

    public function almacenDesdeDestino(DestinoMaterial $destino, User $actor): AlmacenMaterial
    {
        $almacen = AlmacenMaterial::query()->lockForUpdate()->findOrFail($destino->id);
        $cambios = [];

        if (! $almacen->codigo) {
            $cambios['codigo'] = 'ALM-'.Str::upper(
                substr(str_replace('-', '', $almacen->id), 0, 8),
            );
        }

        if (! $almacen->tipo) {
            $cambios['tipo'] = TipoAlmacenMaterial::Virtual;
        }

        if (($cambios['tipo'] ?? $almacen->tipo) === TipoAlmacenMaterial::Virtual
            && $almacen->requiere_ubicacion_fisica) {
            $cambios['requiere_ubicacion_fisica'] = false;
        }

        if ($cambios !== []) {
            $almacen->update([
                ...$cambios,
                'actualizado_por_user_id' => $actor->id,
            ]);
        }

        return $almacen->refresh();
    }

    public function asegurarSaldo(FolioMaterial $folio, AlmacenMaterial $almacen): void
    {
        DB::table('saldos_materiales_almacenes')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'folio_id' => $folio->folio_id,
            'almacen_material_id' => $almacen->id,
            'cantidad_actual' => 0,
            'cantidad_reservada' => 0,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function saldo(
        FolioMaterial $folio,
        AlmacenMaterial $almacen,
        bool $bloquear = true,
    ): SaldoMaterialAlmacen {
        $this->asegurarSaldo($folio, $almacen);
        $consulta = SaldoMaterialAlmacen::query()
            ->where('folio_id', $folio->folio_id)
            ->where('almacen_material_id', $almacen->id);

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->firstOrFail();
    }

    /**
     * @param  array<int, string>  $almacenIds
     * @return Collection<int, SaldoMaterialAlmacen>
     */
    public function saldosBloqueados(
        FolioMaterial $folio,
        array $almacenIds,
    ): Collection {
        $ids = collect($almacenIds)->filter()->unique()->sort()->values();

        return SaldoMaterialAlmacen::query()
            ->where('folio_id', $folio->folio_id)
            ->whereIn('almacen_material_id', $ids)
            ->orderBy('almacen_material_id')
            ->orderBy('folio_id')
            ->lockForUpdate()
            ->get();
    }

    public function inicializarFolio(FolioMaterial $folio): void
    {
        DB::transaction(function () use ($folio): void {
            $folio = FolioMaterial::query()
                ->with('folio.ubicacionActual')
                ->lockForUpdate()
                ->findOrFail($folio->folio_id);
            $bodega = $this->bodegaCentral(bloquear: true);
            $existente = SaldoMaterialAlmacen::query()
                ->where('folio_id', $folio->folio_id)
                ->where('almacen_material_id', $bodega->id)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                return;
            }

            $ubicacion = $folio->folio?->ubicacionActual;
            SaldoMaterialAlmacen::create([
                'folio_id' => $folio->folio_id,
                'almacen_material_id' => $bodega->id,
                'cantidad_actual' => $folio->cantidad_actual,
                'cantidad_reservada' => $folio->cantidad_reservada,
                'camara_id' => (float) $folio->cantidad_actual > 0
                    ? $ubicacion?->camara_id
                    : null,
                'posicion_id' => (float) $folio->cantidad_actual > 0
                    ? $ubicacion?->posicion_id
                    : null,
                'version' => 0,
            ]);
        }, attempts: 3);
    }

    private function saldoCompatibilidad(FolioMaterial $folio): SaldoMaterialAlmacen
    {
        $ubicados = SaldoMaterialAlmacen::where('folio_id', $folio->folio_id)->where('cantidad_actual', '>', 0)
            ->whereNotNull('camara_id')->whereHas('almacen', fn ($q) => $q->where('tipo', 'fisica'))->lockForUpdate()->get();
        if ($ubicados->count() > 1) {
            throw new DomainException('El folio tiene más de una existencia en plano. Usa el inventario por bodegas para regularizar su ubicación.');
        }
        if ($ubicados->isNotEmpty()) {
            return $ubicados->first();
        }
        $pendientes = SaldoMaterialAlmacen::where('folio_id', $folio->folio_id)->where('cantidad_actual', '>', 0)
            ->whereHas('almacen', fn ($q) => $q->where('tipo', 'fisica')->where('requiere_ubicacion_fisica', true))->lockForUpdate()->get();
        if ($pendientes->count() === 1) {
            return $pendientes->first();
        }
        if ($pendientes->count() > 1) {
            throw new DomainException('Hay varias existencias pendientes de ubicación. Usa el inventario por bodegas.');
        }

        return $this->saldo($folio, $this->bodegaCentral(bloquear: true));
    }

    public function aplicarCambioLegado(FolioMaterial $folio): void
    {
        $deltaActual = round(
            (float) $folio->cantidad_actual - (float) $folio->getOriginal('cantidad_actual'),
            3,
        );
        $deltaReservada = round(
            (float) $folio->cantidad_reservada - (float) $folio->getOriginal('cantidad_reservada'),
            3,
        );

        if (abs($deltaActual) <= 0.0001 && abs($deltaReservada) <= 0.0001) {
            return;
        }

        DB::transaction(function () use ($folio, $deltaActual, $deltaReservada): void {
            $bloqueado = FolioMaterial::query()
                ->with('folio')
                ->lockForUpdate()
                ->findOrFail($folio->folio_id);
            $saldo = $this->saldoCompatibilidad($bloqueado);
            $cantidadActual = round((float) $saldo->cantidad_actual + $deltaActual, 3);
            $cantidadReservada = round(
                (float) $saldo->cantidad_reservada + $deltaReservada,
                3,
            );

            $this->validarCantidades($cantidadActual, $cantidadReservada);
            $saldo->update([
                'cantidad_actual' => $cantidadActual,
                'cantidad_reservada' => $cantidadReservada,
                'camara_id' => $cantidadActual > 0 ? $saldo->camara_id : null,
                'posicion_id' => $cantidadActual > 0 ? $saldo->posicion_id : null,
                'version' => (int) $saldo->version + 1,
            ]);

            if ($cantidadActual <= 0.0001) {
                UbicacionActual::withoutEvents(fn () => UbicacionActual::query()
                    ->where('folio_id', $folio->folio_id)
                    ->delete());
            }

            $this->actualizarEstadoGlobal($bloqueado, (float) $folio->cantidad_actual);
            $this->validarProyeccion($bloqueado->folio_id);
        }, attempts: 3);
    }

    public function sincronizarProyeccion(FolioMaterial|string $folio): FolioMaterial
    {
        $folioId = $folio instanceof FolioMaterial ? $folio->folio_id : $folio;

        return DB::transaction(function () use ($folioId): FolioMaterial {
            $material = FolioMaterial::query()
                ->with('folio')
                ->lockForUpdate()
                ->findOrFail($folioId);
            $totales = SaldoMaterialAlmacen::query()
                ->where('folio_id', $folioId)
                ->selectRaw(
                    'COALESCE(SUM(cantidad_actual), 0) AS cantidad_actual, '
                    .'COALESCE(SUM(cantidad_reservada), 0) AS cantidad_reservada',
                )
                ->firstOrFail();
            $cantidadActual = round((float) $totales->cantidad_actual, 3);
            $cantidadReservada = round((float) $totales->cantidad_reservada, 3);

            $this->validarCantidades($cantidadActual, $cantidadReservada);
            $material->forceFill([
                'cantidad_actual' => $cantidadActual,
                'cantidad_reservada' => $cantidadReservada,
            ])->saveQuietly();
            $this->sincronizarUbicacionCompatibilidad($material);
            $this->actualizarEstadoGlobal($material, $cantidadActual);
            $this->validarProyeccion($folioId);

            return $material->refresh();
        }, attempts: 3);
    }

    public function sincronizarUbicacionDesdeCompatibilidad(FolioMaterial $folio): void
    {
        DB::transaction(function () use ($folio): void {
            $folio = FolioMaterial::query()
                ->with('folio.ubicacionActual')
                ->lockForUpdate()
                ->findOrFail($folio->folio_id);
            $saldo = $this->saldoCompatibilidad($folio);
            $ubicacion = $folio->folio?->ubicacionActual;

            $saldo->update([
                'camara_id' => (float) $saldo->cantidad_actual > 0
                    ? $ubicacion?->camara_id
                    : null,
                'posicion_id' => (float) $saldo->cantidad_actual > 0
                    ? $ubicacion?->posicion_id
                    : null,
                'version' => (int) $saldo->version + 1,
            ]);
        }, attempts: 3);
    }

    public function validarProyeccion(string $folioId): void
    {
        $material = FolioMaterial::query()->findOrFail($folioId);
        $totales = SaldoMaterialAlmacen::query()
            ->where('folio_id', $folioId)
            ->selectRaw(
                'COALESCE(SUM(cantidad_actual), 0) AS cantidad_actual, '
                .'COALESCE(SUM(cantidad_reservada), 0) AS cantidad_reservada',
            )
            ->firstOrFail();

        if (abs((float) $material->cantidad_actual - (float) $totales->cantidad_actual) > 0.0001
            || abs((float) $material->cantidad_reservada - (float) $totales->cantidad_reservada) > 0.0001) {
            throw new LogicException(
                'La proyección global del folio no coincide con sus saldos por almacén.',
            );
        }
    }

    private function sincronizarUbicacionCompatibilidad(FolioMaterial $folio): void
    {
        $saldo = SaldoMaterialAlmacen::where('folio_id', $folio->folio_id)->where('cantidad_actual', '>', 0)
            ->whereNotNull('camara_id')->whereHas('almacen', fn ($q) => $q->where('tipo', 'fisica'))->first();

        if (! $saldo || (float) $saldo->cantidad_actual <= 0 || ! $saldo->camara_id) {
            UbicacionActual::withoutEvents(fn () => UbicacionActual::query()
                ->where('folio_id', $folio->folio_id)
                ->delete());

            return;
        }

        UbicacionActual::withoutEvents(fn () => UbicacionActual::query()->updateOrCreate(
            ['folio_id' => $folio->folio_id],
            [
                'camara_id' => $saldo->camara_id,
                'posicion_id' => $saldo->posicion_id,
                'ubicado_at' => now(),
            ],
        ));
    }

    private function actualizarEstadoGlobal(FolioMaterial $folio, float $totalEmpresa): void
    {
        $folio->loadMissing('folio');

        if (! $folio->folio) {
            return;
        }

        if ($totalEmpresa <= 0.0001) {
            $folio->folio->update([
                'estado_operacional' => EstadoOperacionalFolio::Agotado,
                'activo' => false,
            ]);

            return;
        }

        $pendiente = SaldoMaterialAlmacen::where('folio_id', $folio->folio_id)->where('cantidad_actual', '>', 0)->whereNull('camara_id')
            ->whereHas('almacen', fn ($q) => $q->where('tipo', 'fisica')->where('requiere_ubicacion_fisica', true))->exists();
        $estado = $folio->motivo_bloqueo ? EstadoOperacionalFolio::Bloqueado
            : ($pendiente ? EstadoOperacionalFolio::PendienteUbicacion : EstadoOperacionalFolio::Disponible);

        if (! $folio->folio->activo || $folio->folio->estado_operacional !== $estado) {
            $folio->folio->update([
                'estado_operacional' => $estado,
                'activo' => true,
            ]);
        }
    }

    private function validarCantidades(float $actual, float $reservada): void
    {
        if ($actual < -0.0001) {
            throw new DomainException('El saldo de almacén no puede ser negativo.');
        }

        if ($reservada < -0.0001 || $reservada > $actual + 0.0001) {
            throw new DomainException(
                'La cantidad reservada debe estar entre cero y la cantidad actual.',
            );
        }
    }
}
