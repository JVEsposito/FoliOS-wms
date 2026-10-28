<?php

namespace App\Services\Envases;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;

/** Corrige una obligación histórica; nunca ingresa ni retira envases físicos. */
class ServicioCorreccionSaldoHistoricoEnvases
{
    public function corregir(MovimientoEnvase $seleccionado, array $datos, User $actor): MovimientoEnvase
    {
        $hash = hash('sha256', json_encode([$seleccionado->id, $datos], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($seleccionado, $datos, $actor, $hash): MovimientoEnvase {
            // El mismo orden de bloqueo que las correcciones y anulaciones de Romana.
            $recepcion = $seleccionado->recepcion_romana_id
                ? RecepcionRomana::query()->lockForUpdate()->findOrFail($seleccionado->recepcion_romana_id) : null;
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            if ($existente = MovimientoEnvase::where('operacion_id', $datos['operacion_id'])->first()) {
                if (($existente->datos['correccion_cuenta']['payload_hash'] ?? null) !== $hash || $existente->creado_por_user_id !== $actor->id) {
                    throw new ConflictoOperacion('El identificador de operación ya se usó con otros datos.');
                }

                return $existente;
            }
            $origen = MovimientoEnvase::query()->lockForUpdate()->findOrFail($seleccionado->id);
            if ($origen->temporada_id !== $temporada->id || $origen->movimiento_origen_id !== null
                || ! in_array($origen->tipo_movimiento, [TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::AperturaCuenta, TipoMovimientoEnvase::AjusteCuenta], true)
                || $recepcion?->estado === EstadoRecepcionRomana::Anulada || ! empty($origen->datos['correccion_propiedad'])) {
                throw new ConflictoOperacion('Selecciona un ingreso en arriendo, apertura o ajuste vigente de la temporada activa.');
            }
            $vinculados = MovimientoEnvase::where('movimiento_origen_id', $origen->id)->orderBy('id')->lockForUpdate()->get();
            if ($vinculados->contains(fn ($m) => in_array($m->tipo_movimiento, [TipoMovimientoEnvase::CorreccionCantidad,
                TipoMovimientoEnvase::CorreccionPropiedad, TipoMovimientoEnvase::ReversionAjusteCuenta, TipoMovimientoEnvase::AnulacionRecepcion], true))) {
                throw new ConflictoOperacion('El asiento ya tiene otra corrección o anulación. Requiere conciliación.');
            }
            $actual = $origen->cantidad * $origen->signo_cuenta + $vinculados
                ->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionCuenta)
                ->sum(fn ($m) => $m->cantidad * $m->signo_cuenta);
            if ($actual !== $datos['impacto_conocido']) {
                throw new ConflictoOperacion('La cuenta cambió desde que abriste la corrección. Actualiza antes de confirmar.');
            }
            $diferencia = $datos['saldo_correcto'] - $actual;
            if ($diferencia === 0) {
                throw new ConflictoOperacion('El importe correcto coincide con el vigente.');
            }
            $ahora = now();

            return MovimientoEnvase::create([
                'operacion_id' => $datos['operacion_id'], 'temporada_id' => $origen->temporada_id, 'cliente_id' => $origen->cliente_id,
                'recepcion_romana_id' => $origen->recepcion_romana_id, 'documento_tipo' => 'correccion_cuenta_envases',
                'documento_id' => $origen->id, 'numero_documento' => $origen->numero_documento,
                'tipo_movimiento' => TipoMovimientoEnvase::CorreccionCuenta, 'tipo_envase' => $origen->tipo_envase, 'propiedad' => $origen->propiedad,
                'cantidad' => abs($diferencia), 'signo_cuenta' => $diferencia < 0 ? -1 : 1, 'signo_existencia' => 0,
                'movimiento_origen_id' => $origen->id, 'ocurrido_at' => $ahora,
                'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $actor->id,
                'datos' => ['correccion_cuenta' => ['motivo' => $datos['motivo'], 'corregido_at' => $ahora->toAtomString(),
                    'impacto_anterior' => $actual, 'saldo_correcto' => $datos['saldo_correcto'], 'diferencia' => $diferencia, 'payload_hash' => $hash]],
            ]);
        }, attempts: 3);
    }
}
