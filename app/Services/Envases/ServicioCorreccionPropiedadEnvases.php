<?php

namespace App\Services\Envases;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioCorreccionPropiedadEnvases
{
    /**
     * Corrige todas las líneas de la recepción: el concepto de Romana es único
     * para la recepción, aunque existan varios tipos de envase validados.
     */
    public function corregir(MovimientoEnvase $seleccionado, ConceptoEnvasesRomana $nuevo, string $motivo, User $usuario): void
    {
        DB::transaction(function () use ($seleccionado, $nuevo, $motivo, $usuario): void {
            if (! $seleccionado->recepcion_romana_id) {
                throw new ConflictoOperacion('Selecciona un ingreso de Romana validado.');
            }

            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($seleccionado->recepcion_romana_id);
            if ($recepcion->temporada_id !== app(ServicioTemporadaActiva::class)->buscar(bloquear: true)?->id
                || $recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada
                || $recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases
                || ! in_array($recepcion->concepto_envases, [ConceptoEnvasesRomana::Compra, ConceptoEnvasesRomana::Arriendo], true)) {
                throw new ConflictoOperacion('Solo se corrigen ingresos de envases validados en la temporada activa.');
            }
            if ($recepcion->concepto_envases === $nuevo) {
                throw new ConflictoOperacion('La recepción ya tiene esa propiedad.');
            }

            $ingresos = MovimientoEnvase::query()
                ->where('recepcion_romana_id', $recepcion->id)
                ->whereIn('tipo_movimiento', [TipoMovimientoEnvase::RecepcionCompra->value, TipoMovimientoEnvase::RecepcionArriendo->value])
                ->lockForUpdate()->get();
            if (! $ingresos->contains('id', $seleccionado->id)
                || $ingresos->isEmpty()
                || $ingresos->count() !== $recepcion->detallesEnvases()->count()
                || MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
                    ->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionPropiedad->value)->exists()) {
                throw new ConflictoOperacion('La recepción ya se corrigió o sus movimientos no coinciden con la validación.');
            }

            $tipoAnterior = $recepcion->concepto_envases === ConceptoEnvasesRomana::Compra
                ? TipoMovimientoEnvase::RecepcionCompra : TipoMovimientoEnvase::RecepcionArriendo;
            $propiedadAnterior = $recepcion->concepto_envases === ConceptoEnvasesRomana::Compra
                ? PropiedadEnvase::Propia : PropiedadEnvase::Arrendada;
            $ids = $ingresos->pluck('id');
            foreach ($ingresos as $ingreso) {
                if ($ingreso->tipo_movimiento !== $tipoAnterior
                    || $ingreso->propiedad !== $propiedadAnterior
                    || $ingreso->signo_existencia !== 1
                    || $ingreso->signo_cuenta !== ($propiedadAnterior === PropiedadEnvase::Propia ? 0 : 1)
                    || $ingreso->movimiento_origen_id !== null
                    || $ingreso->temporada_id !== $recepcion->temporada_id
                    || $ingreso->cliente_id !== $recepcion->cliente_id) {
                    throw new ConflictoOperacion('Los ingresos no corresponden al concepto original de Romana.');
                }
            }

            // Incluso una guía cancelada o un despacho revertido conserva una
            // referencia histórica: en esos casos se necesita otra conciliación.
            if (DetalleGuiaDespachoEnvase::query()->whereIn('movimiento_origen_id', $ids)->exists()
                || MovimientoEnvase::query()->whereIn('movimiento_origen_id', $ids)->exists()) {
                throw new ConflictoOperacion('El ingreso ya figura en una reserva o guía de despacho. No se puede corregir su propiedad.');
            }
            if ($propiedadAnterior === PropiedadEnvase::Propia
                && MovimientoEnvase::query()->where('temporada_id', $recepcion->temporada_id)
                    ->whereIn('tipo_envase', $ingresos->pluck('tipo_envase')->map(fn ($tipo) => $tipo->value))
                    ->where('propiedad', PropiedadEnvase::Propia->value)
                    ->whereNull('movimiento_origen_id')
                    ->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)->exists()) {
                throw new ConflictoOperacion('Hay despachos históricos propios sin origen identificado. Se requiere conciliación antes de corregir.');
            }

            $ahora = now();
            $tipoNuevo = $nuevo === ConceptoEnvasesRomana::Arriendo
                ? TipoMovimientoEnvase::RecepcionArriendo : TipoMovimientoEnvase::RecepcionCompra;
            $propiedadNueva = $nuevo === ConceptoEnvasesRomana::Arriendo
                ? PropiedadEnvase::Arrendada : PropiedadEnvase::Propia;
            foreach ($ingresos as $ingreso) {
                $comun = [
                    'temporada_id' => $ingreso->temporada_id,
                    'cliente_id' => $ingreso->cliente_id,
                    'recepcion_romana_id' => $recepcion->id,
                    'documento_tipo' => $ingreso->documento_tipo,
                    'documento_id' => $ingreso->documento_id,
                    'numero_documento' => $ingreso->numero_documento,
                    'tipo_envase' => $ingreso->tipo_envase,
                    'cantidad' => $ingreso->cantidad,
                    'ocurrido_at' => $ahora,
                    'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
                    'creado_por_user_id' => $usuario->id,
                ];
                $traza = [
                    'ingreso_original_id' => $ingreso->id,
                    'concepto_anterior' => $recepcion->concepto_envases->value,
                    'concepto_nuevo' => $nuevo->value,
                    'motivo' => $motivo,
                    'usuario_id' => $usuario->id,
                    'corregido_at' => $ahora->toAtomString(),
                ];
                MovimientoEnvase::create([
                    ...$comun,
                    'operacion_id' => (string) Str::uuid(),
                    'tipo_movimiento' => TipoMovimientoEnvase::CorreccionPropiedad,
                    'signo_cuenta' => -$ingreso->signo_cuenta,
                    'signo_existencia' => -1,
                    'propiedad' => $propiedadAnterior,
                    'movimiento_origen_id' => $ingreso->id,
                    'datos' => ['correccion_propiedad' => $traza],
                ]);
                MovimientoEnvase::create([
                    ...$comun,
                    'operacion_id' => (string) Str::uuid(),
                    'tipo_movimiento' => $tipoNuevo,
                    'signo_cuenta' => $propiedadNueva === PropiedadEnvase::Arrendada ? 1 : 0,
                    'signo_existencia' => 1,
                    'propiedad' => $propiedadNueva,
                    'ingreso_at' => $ahora,
                    'datos' => ['correccion_propiedad' => $traza],
                ]);
            }
            $recepcion->update(['concepto_envases' => $nuevo, 'version' => $recepcion->version + 1]);
        }, attempts: 3);
    }
}
