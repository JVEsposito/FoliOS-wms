<?php

namespace App\Services\Envases;

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
use App\Models\ValidacionMp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioCorreccionCantidadEnvases
{
    public function corregir(MovimientoEnvase $seleccionado, int $cantidadCorrecta, string $motivo, User $usuario): void
    {
        DB::transaction(function () use ($seleccionado, $cantidadCorrecta, $motivo, $usuario): void {
            if ($cantidadCorrecta < 1) {
                throw new ConflictoOperacion('La cantidad correcta debe ser positiva.');
            }
            if (! $seleccionado->recepcion_romana_id) {
                throw new ConflictoOperacion('Selecciona un ingreso de Romana validado.');
            }

            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($seleccionado->recepcion_romana_id);
            if (! $recepcion->temporada()->where('activa', true)->exists()
                || $recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases
                || $recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada) {
                throw new ConflictoOperacion('Solo se corrigen recepciones de envases validadas en la temporada activa.');
            }

            $ingreso = MovimientoEnvase::query()->lockForUpdate()->findOrFail($seleccionado->id);
            $detalle = $recepcion->detallesEnvases()->where('tipo_envase', $ingreso->tipo_envase->value)->lockForUpdate()->first();
            $validacion = ValidacionMp::query()->where('recepcion_romana_id', $recepcion->id)->first();

            if (! $detalle || ! $validacion || $validacion->estado !== EstadoValidacionMp::Validada
                || $validacion->segmentos()->exists() || $recepcion->lotesMateriaPrima()->exists()
                || ! in_array($ingreso->tipo_movimiento, [TipoMovimientoEnvase::RecepcionCompra, TipoMovimientoEnvase::RecepcionArriendo], true)
                || $ingreso->recepcion_romana_id !== $recepcion->id
                || $ingreso->cliente_id !== $recepcion->cliente_id
                || $ingreso->temporada_id !== $recepcion->temporada_id
                || $ingreso->movimiento_origen_id !== null
                || $ingreso->signo_existencia !== 1
                || $ingreso->signo_cuenta !== ($ingreso->propiedad === PropiedadEnvase::Arrendada ? 1 : 0)
                || $recepcion->cantidad_envases_declarados < $ingreso->cantidad
                || $detalle->cantidad_declarada !== $ingreso->cantidad
                || $detalle->cantidad_validada !== $ingreso->cantidad) {
                throw new ConflictoOperacion('El ingreso tiene diferencias o vínculos que requieren conciliación manual.');
            }
            if ($cantidadCorrecta >= $ingreso->cantidad) {
                throw new ConflictoOperacion('La cantidad correcta debe ser menor que la registrada originalmente.');
            }

            if (DetalleGuiaDespachoEnvase::query()->where('movimiento_origen_id', $ingreso->id)->exists()
                || MovimientoEnvase::query()->where('movimiento_origen_id', $ingreso->id)->exists()
                || MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
                    ->whereIn('tipo_movimiento', [
                        TipoMovimientoEnvase::CorreccionCantidad->value,
                        TipoMovimientoEnvase::CorreccionPropiedad->value,
                    ])->exists()) {
                throw new ConflictoOperacion('El ingreso ya tiene una guía, reserva o corrección vinculada. Requiere conciliación.');
            }
            if ($ingreso->propiedad === PropiedadEnvase::Propia
                && MovimientoEnvase::query()->where('temporada_id', $ingreso->temporada_id)
                    ->where('tipo_envase', $ingreso->tipo_envase->value)
                    ->where('propiedad', PropiedadEnvase::Propia->value)
                    ->whereNull('movimiento_origen_id')
                    ->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)->exists()) {
                throw new ConflictoOperacion('Hay despachos propios sin origen identificado. Se requiere conciliación.');
            }

            $diferencia = $ingreso->cantidad - $cantidadCorrecta;
            $ahora = now();
            MovimientoEnvase::create([
                'operacion_id' => (string) Str::uuid(),
                'temporada_id' => $ingreso->temporada_id,
                'cliente_id' => $ingreso->cliente_id,
                'recepcion_romana_id' => $recepcion->id,
                'documento_tipo' => $ingreso->documento_tipo,
                'documento_id' => $ingreso->documento_id,
                'numero_documento' => $ingreso->numero_documento,
                'tipo_movimiento' => TipoMovimientoEnvase::CorreccionCantidad,
                'tipo_envase' => $ingreso->tipo_envase,
                'cantidad' => $diferencia,
                'signo_cuenta' => -$ingreso->signo_cuenta,
                'signo_existencia' => -1,
                'propiedad' => $ingreso->propiedad,
                'movimiento_origen_id' => $ingreso->id,
                'ocurrido_at' => $ahora,
                'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
                'creado_por_user_id' => $usuario->id,
                'datos' => ['correccion_cantidad' => [
                    'cantidad_anterior' => $ingreso->cantidad,
                    'cantidad_correcta' => $cantidadCorrecta,
                    'motivo' => $motivo,
                    'usuario_id' => $usuario->id,
                    'corregido_at' => $ahora->toAtomString(),
                ]],
            ]);
            $detalle->update(['cantidad_declarada' => $cantidadCorrecta, 'cantidad_validada' => $cantidadCorrecta]);
            $recepcion->update([
                'cantidad_envases_declarados' => $recepcion->cantidad_envases_declarados - $diferencia,
                'version' => $recepcion->version + 1,
            ]);
        }, attempts: 3);
    }
}
