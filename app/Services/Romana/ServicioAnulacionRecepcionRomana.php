<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioAnulacionRecepcionRomana
{
    public function anular(RecepcionRomana $seleccionada, array $datos, User $actor): RecepcionRomana
    {
        $hash = hash('sha256', json_encode([$seleccionada->id, $datos], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($seleccionada, $datos, $actor, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($seleccionada->id);
            // Lectura actual: no abrir un snapshot antes de adquirir el mutex de temporada.
            $evento = EventoRecepcionRomana::where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->tipo !== TipoEventoRomana::RecepcionAnulada
                    || $evento->payload_hash !== $hash || $evento->user_id !== $actor->id) {
                    throw new ConflictoOperacion('El identificador de operación ya se usó con otros datos.');
                }

                return $recepcion;
            }
            if ($recepcion->temporada_id !== app(ServicioTemporadaActiva::class)->obtener(bloquear: true)->id) {
                throw new ConflictoOperacion('Solo se anulan recepciones de la temporada activa.');
            }
            if ($recepcion->estado === EstadoRecepcionRomana::Anulada) {
                throw new ConflictoOperacion('La recepción ya está anulada.');
            }
            if ($recepcion->version !== $datos['version_conocida']) {
                throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de anular.');
            }
            if ($recepcion->estado_validacion_mp === EstadoValidacionMp::EnCurso) {
                throw new ConflictoOperacion('Un validador está trabajando en esta recepción. Resuelve su validación antes de anular.');
            }
            if ($recepcion->lotesMateriaPrima()->exists()
                || $recepcion->validacionesMp()->whereHas('segmentos')->exists()) {
                throw new ConflictoOperacion('La recepción tiene segmentos o lotes de materia prima vinculados. Requiere conciliación antes de anular.');
            }
            $movimientos = $recepcion->movimientosEnvases()->orderBy('id')->lockForUpdate()->get();
            $ids = $movimientos->pluck('id');
            if (DetalleGuiaDespachoEnvase::whereIn('movimiento_origen_id', $ids)->exists()
                || MovimientoEnvase::whereIn('movimiento_origen_id', $ids)->exists()
                || $movimientos->contains(fn ($m) => $m->movimiento_origen_id !== null
                    || ! in_array($m->tipo_movimiento, [TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::RecepcionCompra], true))) {
                throw new ConflictoOperacion('La recepción tiene guías, reservas o correcciones vinculadas. Requiere conciliación antes de anular.');
            }
            foreach ($movimientos as $movimiento) {
                // Los despachos propios antiguos pueden no conservar un origen individual.
                $sinOrigen = MovimientoEnvase::where('temporada_id', $recepcion->temporada_id)
                    ->where('tipo_envase', $movimiento->tipo_envase->value)->where('propiedad', $movimiento->propiedad->value)
                    ->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)->whereNull('movimiento_origen_id')->exists();
                $fisico = (int) MovimientoEnvase::where('temporada_id', $recepcion->temporada_id)
                    ->where('tipo_envase', $movimiento->tipo_envase->value)->where('propiedad', $movimiento->propiedad->value)
                    ->selectRaw('SUM(CAST(cantidad AS SIGNED) * signo_existencia) as saldo')->value('saldo');
                if ($sinOrigen || $movimiento->signo_existencia !== 1 || $fisico < $movimiento->cantidad) {
                    throw new ConflictoOperacion('La existencia tiene salidas o diferencias que requieren conciliación antes de anular.');
                }
            }
            $ahora = now();
            $reversas = [];
            foreach ($movimientos as $movimiento) {
                $reversa = MovimientoEnvase::create([
                    'operacion_id' => (string) Str::uuid(), 'temporada_id' => $recepcion->temporada_id,
                    'cliente_id' => $movimiento->cliente_id, 'recepcion_romana_id' => $recepcion->id,
                    'documento_tipo' => 'anulacion_recepcion_romana', 'documento_id' => $recepcion->id,
                    'numero_documento' => $movimiento->numero_documento, 'tipo_movimiento' => TipoMovimientoEnvase::AnulacionRecepcion,
                    'tipo_envase' => $movimiento->tipo_envase, 'propiedad' => $movimiento->propiedad, 'cantidad' => $movimiento->cantidad,
                    'signo_cuenta' => -$movimiento->signo_cuenta, 'signo_existencia' => -$movimiento->signo_existencia,
                    'movimiento_origen_id' => $movimiento->id, 'ocurrido_at' => $ahora,
                    'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $actor->id,
                    'datos' => ['anulacion_recepcion' => ['motivo' => $datos['motivo'], 'corregido_at' => $ahora->toAtomString()]],
                ]);
                $reversas[] = $reversa->id;
            }
            $anterior = $recepcion->estado;
            $recepcion->update(['estado' => EstadoRecepcionRomana::Anulada, 'version' => $recepcion->version + 1]);
            EventoRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'recepcion_romana_id' => $recepcion->id,
                'tipo' => TipoEventoRomana::RecepcionAnulada, 'estado_anterior' => $anterior, 'estado_nuevo' => EstadoRecepcionRomana::Anulada,
                'user_id' => $actor->id, 'ocurrido_at' => $ahora,
                'datos' => ['motivo' => $datos['motivo'], 'version_anterior' => $datos['version_conocida'], 'reversas' => $reversas],
            ]);

            return $recepcion;
        }, attempts: 3);
    }
}
