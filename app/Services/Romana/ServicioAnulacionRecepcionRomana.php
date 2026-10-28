<?php

namespace App\Services\Romana;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\EventoLoteMateriaPrima;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\TrazabilidadFolioOrigen;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ServicioAnulacionRecepcionRomana
{
    public function anular(RecepcionRomana $seleccionada, array $datos, User $actor): RecepcionRomana
    {
        return $this->procesar($seleccionada, $datos, $actor, false);
    }

    public function eliminar(RecepcionRomana $seleccionada, array $datos, User $actor): RecepcionRomana
    {
        Gate::forUser($actor)->authorize('eliminar-recepciones-romana');

        return $this->procesar($seleccionada, $datos, $actor, true);
    }

    private function procesar(RecepcionRomana $seleccionada, array $datos, User $actor, bool $eliminar): RecepcionRomana
    {
        $payload = $eliminar ? ['eliminar', $seleccionada->id, $datos] : [$seleccionada->id, $datos];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $tipoEvento = $eliminar ? TipoEventoRomana::RecepcionEliminada : TipoEventoRomana::RecepcionAnulada;

        return DB::transaction(function () use ($seleccionada, $datos, $actor, $hash, $eliminar, $tipoEvento): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($seleccionada->id);
            // Lectura actual: no abrir un snapshot antes de adquirir el mutex de temporada.
            $evento = EventoRecepcionRomana::where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->tipo !== $tipoEvento
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
            if (! $eliminar && $recepcion->estado_validacion_mp === EstadoValidacionMp::EnCurso) {
                throw new ConflictoOperacion('Un validador está trabajando en esta recepción. Resuelve su validación antes de anular.');
            }
            if (! $eliminar && ($recepcion->lotesMateriaPrima()->exists()
                || $recepcion->validacionesMp()->whereHas('segmentos')->exists())) {
                throw new ConflictoOperacion('La recepción tiene segmentos o lotes de materia prima vinculados. Requiere conciliación antes de anular.');
            }
            $movimientos = $recepcion->movimientosEnvases()->orderBy('id')->lockForUpdate()->get();
            $ids = $movimientos->pluck('id');
            if ($eliminar) {
                $this->asegurarEnvasesDisponibles($recepcion, $movimientos);
            }
            if (DetalleGuiaDespachoEnvase::whereIn('movimiento_origen_id', $ids)->exists()
                || MovimientoEnvase::whereIn('movimiento_origen_id', $ids)
                    ->when($eliminar, fn ($q) => $q->where(fn ($q) => $q
                        ->whereNull('recepcion_romana_id')->orWhere('recepcion_romana_id', '!=', $recepcion->id)))
                    ->exists()
                || (! $eliminar && $movimientos->contains(fn ($m) => $m->movimiento_origen_id !== null
                    || ! in_array($m->tipo_movimiento, [TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::RecepcionCompra], true)))) {
                throw new ConflictoOperacion('La recepción tiene guías, reservas o correcciones vinculadas. Requiere conciliación antes de anular.');
            }
            foreach ($movimientos as $movimiento) {
                if ($eliminar) {
                    continue;
                }
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
            $materiaPrima = $eliminar ? $this->retirarMateriaPrima($recepcion, $datos['motivo'], $actor) : [];
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
            $recepcion->update(['estado' => EstadoRecepcionRomana::Anulada, 'version' => $recepcion->version + 1,
                ...($eliminar ? ['eliminada_at' => $ahora, 'guia_vigente' => null] : [])]);
            EventoRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'recepcion_romana_id' => $recepcion->id,
                'tipo' => $tipoEvento, 'estado_anterior' => $anterior, 'estado_nuevo' => EstadoRecepcionRomana::Anulada,
                'user_id' => $actor->id, 'ocurrido_at' => $ahora,
                'datos' => ['motivo' => $datos['motivo'], 'version_anterior' => $datos['version_conocida'], 'reversas' => $reversas,
                    'materia_prima' => $materiaPrima],
            ]);

            return $recepcion;
        }, attempts: 3);
    }

    private function asegurarEnvasesDisponibles(RecepcionRomana $recepcion, Collection $movimientos): void
    {
        foreach ($movimientos->groupBy(fn ($m) => $m->tipo_envase->value.':'.$m->propiedad->value.':'.$m->cliente_id) as $grupo) {
            $retirar = $grupo->sum(fn ($m) => $m->cantidad * $m->signo_existencia);
            if ($retirar <= 0) {
                continue;
            }
            $primero = $grupo->first();
            $fisico = (int) MovimientoEnvase::where('temporada_id', $recepcion->temporada_id)
                ->where('tipo_envase', $primero->tipo_envase->value)->where('propiedad', $primero->propiedad->value)
                ->when($primero->propiedad !== PropiedadEnvase::Propia, fn ($q) => $q->where('cliente_id', $primero->cliente_id))
                ->selectRaw('SUM(CAST(cantidad AS SIGNED) * signo_existencia) as saldo')->value('saldo');
            if ($fisico < $retirar) {
                throw new ConflictoOperacion('Los envases de esta recepción ya tienen salidas. Resuelve esas salidas antes de eliminarla.');
            }
        }
    }

    private function retirarMateriaPrima(RecepcionRomana $recepcion, string $motivo, User $actor): array
    {
        $validaciones = $recepcion->validacionesMp()->orderBy('id')->lockForUpdate()->get();
        $segmentos = SegmentoValidacionMp::whereIn('validacion_mp_id', $validaciones->modelKeys())
            ->orderBy('id')->lockForUpdate()->get();
        $lotes = $recepcion->lotesMateriaPrima()->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($lotes as $lote) {
            if ($lote->estado === EstadoLoteMateriaPrima::HidrocoolerEnCurso
                || $lote->entregasProceso()->whereNull('anulado_at')->lockForUpdate()->exists()
                || TrazabilidadFolioOrigen::where('lote_materia_prima_id', $lote->id)->lockForUpdate()->exists()) {
                throw new ConflictoOperacion("El lote {$lote->numero_lote} está en hidrocooler o tiene entregas a Packing o pallets vinculados. Resuelve esa operación antes de eliminar la recepción.");
            }
        }
        foreach ($lotes as $lote) {
            $anterior = $lote->estado;
            $lote->update(['estado' => EstadoLoteMateriaPrima::Anulado, 'clave_numero_vigente' => null,
                'version' => $lote->version + 1, 'actualizado_por_user_id' => $actor->id,
                'anulado_por_user_id' => $actor->id, 'anulado_at' => now(), 'motivo_anulacion' => $motivo]);
            EventoLoteMateriaPrima::create(['lote_materia_prima_id' => $lote->id,
                'operacion_id' => (string) Str::uuid(), 'tipo' => 'lote_anulado',
                'estado_anterior' => $anterior->value, 'estado_nuevo' => EstadoLoteMateriaPrima::Anulado->value,
                'user_id' => $actor->id, 'ocurrido_at' => now(),
                'datos' => ['motivo' => $motivo, 'recepcion_eliminada_id' => $recepcion->id]]);
        }
        foreach ($segmentos as $segmento) {
            $segmento->update(['estado' => 'anulado']);
        }

        return ['lotes' => $lotes->modelKeys(), 'segmentos' => $segmentos->modelKeys()];
    }
}
