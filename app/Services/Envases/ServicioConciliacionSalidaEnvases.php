<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;

/** Repara dos registros de la misma salida física; nunca borra ni cambia el asiento original. */
class ServicioConciliacionSalidaEnvases
{
    public function previsualizar(RecepcionRomana $recepcion, GuiaDespachoEnvase $guia, string $conservar, bool $guiasDistintasVerificadas = false): array
    {
        if (! in_array($conservar, ['romana', 'despacho'], true)) {
            throw new ConflictoOperacion('Elige explícitamente qué cantidades conservar: romana o despacho.');
        }
        $recepcion->refresh();
        $guia->refresh();
        $normalizador = app(ServicioGuiaEmitidaEnvases::class);
        $numero = $normalizador->normalizar($guia->numero_guia_despacho);
        $numeroRomana = $normalizador->normalizar($recepcion->numero_guia_salida);
        if ($guia->estado !== EstadoGuiaDespachoEnvase::Confirmada || $recepcion->estado !== EstadoRecepcionRomana::Cerrado
            || $recepcion->eliminada_at !== null
            || $guia->temporada_id !== $recepcion->temporada_id || $guia->cliente_id !== $recepcion->cliente_id
            || ! $numero || ! $numeroRomana) {
            throw new ConflictoOperacion('Selecciona una recepción cerrada vigente y un despacho confirmado del mismo cliente y temporada, ambos con guía de salida. Las recepciones eliminadas o anuladas no se concilian como salidas vigentes.');
        }
        if ($numero !== $numeroRomana && ! $guiasDistintasVerificadas) {
            throw new ConflictoOperacion('Las guías de salida tienen números distintos. Verifica que corresponden a la misma salida física y confirma expresamente esa revisión; no cambies los números para forzar la coincidencia.');
        }
        $previos = MovimientoEnvase::query()->where('tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value)
            ->where('documento_id', $guia->id)->orderBy('id')->get();
        if ($previos->isNotEmpty()) {
            if ($previos->contains(fn ($m) => ($m->datos['recepcion_id'] ?? null) !== $recepcion->id)) {
                throw new ConflictoOperacion('La guía ya se concilió con otra recepción. Requiere revisión conjunta de todos los documentos.');
            }
            if ($previos->first()->datos['conservar'] !== $conservar) {
                throw new ConflictoOperacion('La pareja ya se concilió con otra fuente. No se cambia el criterio de una conciliación aplicada.');
            }

            return ['aplicada' => true, 'filas' => [], 'acciones' => [], 'hash' => null, 'movimientos' => $previos->pluck('id')->all()];
        }
        $romana = $recepcion->salidasEnvases()->where('cantidad', '>', 0)->get();
        $salidasRomana = MovimientoEnvase::query()->whereIn('id', $romana->pluck('movimiento_envase_id'))->orderBy('id')->get();
        if ($salidasRomana->count() !== $romana->count()) {
            throw new ConflictoOperacion('La salida de Romana no conserva todos sus movimientos. Requiere revisión individual.');
        }
        foreach ($romana as $salida) {
            $movimiento = $salidasRomana->firstWhere('id', $salida->movimiento_envase_id);
            if ($movimiento->recepcion_romana_id !== $recepcion->id || $movimiento->tipo_movimiento !== TipoMovimientoEnvase::SalidaRecepcionFruta
                || $movimiento->tipo_envase !== $salida->tipo_envase || $movimiento->cantidad !== $salida->cantidad) {
                throw new ConflictoOperacion('El detalle de salida de Romana difiere de su asiento. Requiere revisión individual.');
            }
        }
        $despacho = MovimientoEnvase::query()->where('documento_tipo', 'guia_despacho_envases')->where('documento_id', $guia->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)->orderBy('id')->get();
        if ($despacho->contains(fn ($m) => $m->propiedad !== PropiedadEnvase::Propia && $m->movimiento_origen_id === null)) {
            throw new ConflictoOperacion('El despacho histórico no conserva el origen físico de envases de cliente o arrendados. Requiere conciliación individual de inventario.');
        }
        $ambos = $salidasRomana->concat($despacho);
        if ($ambos->contains(fn ($m) => $m->signo_cuenta !== -1 || $m->signo_existencia !== -1 || $m->cantidad <= 0
            || $m->cliente_id !== $recepcion->cliente_id || $m->temporada_id !== $recepcion->temporada_id)) {
            throw new ConflictoOperacion('Los asientos tienen signos, cliente o cantidades incompatibles con una doble salida.');
        }
        if (MovimientoEnvase::query()->whereIn('movimiento_origen_id', $ambos->pluck('id'))->exists()
            || MovimientoEnvase::query()->whereIn('datos->reversa_de_movimiento_id', $despacho->pluck('id'))->exists()
            || MovimientoEnvase::query()->where('tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value)->whereIn('datos->movimiento_conciliado_id', $ambos->pluck('id'))->exists()) {
            throw new ConflictoOperacion('Ya existen correcciones o reversas sobre estas salidas. Requiere revisión individual.');
        }
        $clave = fn ($m) => $m->tipo_envase->value.'|'.$m->propiedad->value;
        $r = $salidasRomana->groupBy($clave);
        $d = $despacho->groupBy($clave);
        $filas = [];
        $acciones = [];
        foreach ($r->keys()->merge($d->keys())->unique()->sort()->values() as $tipo) {
            $cantidadRomana = (int) $r->get($tipo, collect())->sum('cantidad');
            $cantidadDespacho = (int) $d->get($tipo, collect())->sum('cantidad');
            $duplicada = $cantidadRomana > 0 && $cantidadDespacho > 0;
            $filas[] = ['envase_propiedad' => $tipo, 'romana' => $cantidadRomana, 'despacho' => $cantidadDespacho,
                'diferencia' => $cantidadDespacho - $cantidadRomana,
                'conservar' => $duplicada ? ($conservar === 'romana' ? $cantidadRomana : $cantidadDespacho) : $cantidadRomana + $cantidadDespacho];
            // No se utiliza min() ni se elimina el envase que aparece en un solo flujo.
            if ($duplicada) {
                foreach (($conservar === 'romana' ? $d : $r)->get($tipo) as $movimiento) {
                    $acciones[] = $movimiento->only(['id', 'tipo_envase', 'propiedad', 'cantidad', 'movimiento_origen_id']);
                }
            }
        }
        if (! $acciones) {
            throw new ConflictoOperacion('No hay tipos de envase y propiedad repetidos entre ambas salidas. No se modifica ningún saldo.');
        }
        $hash = hash('sha256', json_encode([$recepcion->id, $recepcion->version, $numeroRomana, $guia->id, $guia->version, $numero, $conservar, $filas, $acciones], JSON_THROW_ON_ERROR));

        return ['aplicada' => false, 'filas' => $filas, 'acciones' => $acciones, 'hash' => $hash];
    }

    public function aplicar(RecepcionRomana $recepcion, GuiaDespachoEnvase $guia, string $conservar, string $hash, string $motivo, User $actor, bool $guiasDistintasVerificadas = false): array
    {
        Gate::forUser($actor)->authorize('ajustar-cuenta-envases');
        if (mb_strlen(trim($motivo)) < 10) {
            throw new ConflictoOperacion('Indica un motivo de al menos 10 caracteres para la conciliación.');
        }

        return DB::transaction(function () use ($recepcion, $guia, $conservar, $hash, $motivo, $actor, $guiasDistintasVerificadas): array {
            $normalizador = app(ServicioGuiaEmitidaEnvases::class);
            $numeros = [$normalizador->normalizar($guia->numero_guia_despacho), $normalizador->normalizar($recepcion->numero_guia_salida)];
            $identidades = [$guia->temporada_id, $guia->cliente_id, $recepcion->temporada_id, $recepcion->cliente_id];
            // Dos números distintos se coordinan siempre en el mismo orden, antes de bloquear documentos.
            foreach (collect($numeros)->filter()->unique()->sort(SORT_STRING) as $numero) {
                app(DocumentoSalidaEnvases::class)->bloquear($guia->temporada_id, $guia->cliente_id, $numero);
            }
            $guia = GuiaDespachoEnvase::query()->lockForUpdate()->findOrFail($guia->id);
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            if ($identidades !== [$guia->temporada_id, $guia->cliente_id, $recepcion->temporada_id, $recepcion->cliente_id]
                || $numeros !== [$normalizador->normalizar($guia->numero_guia_despacho), $normalizador->normalizar($recepcion->numero_guia_salida)]) {
                throw new ConflictoOperacion('Los documentos cambiaron. Vuelve a previsualizar la conciliación.');
            }
            if ($guia->temporada_id !== app(ServicioTemporadaActiva::class)->obtener()->id) {
                throw new ConflictoOperacion('Solo se concilian salidas de la temporada activa.');
            }
            $plan = $this->previsualizar($recepcion, $guia, $conservar, $guiasDistintasVerificadas);
            if ($plan['aplicada']) {
                return $plan;
            }
            if (! hash_equals($plan['hash'], $hash)) {
                throw new ConflictoOperacion('Los datos cambiaron desde la previsualización. Revisa el nuevo diagnóstico antes de aplicar.');
            }
            $ids = [];
            foreach ($plan['acciones'] as $accion) {
                $original = MovimientoEnvase::query()->lockForUpdate()->findOrFail($accion['id']);
                $correccion = MovimientoEnvase::create([
                    'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'conciliar-salida-envases:'.$recepcion->id.':'.$guia->id.':'.$original->id)->toString(),
                    'temporada_id' => $guia->temporada_id, 'cliente_id' => $guia->cliente_id, 'recepcion_romana_id' => $recepcion->id,
                    'documento_tipo' => 'conciliacion_salida_envases', 'documento_id' => $guia->id,
                    'numero_documento' => $guia->numero_guia_despacho, 'tipo_movimiento' => TipoMovimientoEnvase::ConciliacionSalida,
                    'tipo_envase' => $original->tipo_envase, 'propiedad' => $original->propiedad, 'cantidad' => $original->cantidad,
                    'signo_cuenta' => 1, 'signo_existencia' => 1,
                    // Despacho conserva su raíz FIFO; Romana conserva la referencia a su salida.
                    'movimiento_origen_id' => $conservar === 'romana' ? $original->movimiento_origen_id : $original->id,
                    'ocurrido_at' => now(), 'ingreso_at' => now(), 'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
                    'creado_por_user_id' => $actor->id,
                    'datos' => ['movimiento_conciliado_id' => $original->id, 'recepcion_id' => $recepcion->id,
                        'guia_id' => $guia->id, 'conservar' => $conservar, 'motivo' => trim($motivo), 'hash_plan' => $hash, 'cantidades_revisadas' => $plan['filas'],
                        'guia_salida_romana' => $recepcion->numero_guia_salida, 'guia_salida_despacho' => $guia->numero_guia_despacho,
                        'guias_distintas_verificadas' => $numeros[0] !== $numeros[1] && $guiasDistintasVerificadas],
                ]);
                $ids[] = $correccion->id;
            }

            return [...$plan, 'aplicada' => true, 'movimientos' => $ids];
        }, attempts: 3);
    }

    /** Saldo que aún se debe revertir al anular un despacho conciliado conservando Romana. */
    public function pendienteAnulacion(MovimientoEnvase $despacho): int
    {
        $compensado = MovimientoEnvase::query()->where('tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value)
            ->where('datos->movimiento_conciliado_id', $despacho->id)->get()->sum(fn ($m) => $m->cantidad * $m->signo_cuenta);
        $pendiente = $despacho->cantidad - $compensado;
        if ($pendiente < 0 || $pendiente > $despacho->cantidad) {
            throw new ConflictoOperacion('La conciliación no cuadra con el despacho. No se puede anular automáticamente.');
        }

        return $pendiente;
    }

    /** Si se anula el respaldo conservado, vuelve a quedar vigente la salida real de Romana. */
    public function restaurarRomanaAlAnular(GuiaDespachoEnvase $guia, User $actor): void
    {
        $conciliaciones = MovimientoEnvase::query()->where('tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value)
            ->where('documento_id', $guia->id)->where('signo_cuenta', 1)->where('datos->conservar', 'despacho')->orderBy('id')->get();
        foreach ($conciliaciones as $c) {
            $operacion = Uuid::uuid5(Uuid::NAMESPACE_URL, 'restaurar-salida-romana:'.$c->id)->toString();
            if (MovimientoEnvase::query()->where('operacion_id', $operacion)->exists()) {
                continue;
            }
            MovimientoEnvase::create([
                ...$c->only(['temporada_id', 'cliente_id', 'recepcion_romana_id', 'documento_tipo', 'documento_id', 'numero_documento', 'tipo_envase', 'propiedad', 'cantidad', 'movimiento_origen_id']),
                'operacion_id' => $operacion, 'tipo_movimiento' => TipoMovimientoEnvase::ConciliacionSalida,
                'signo_cuenta' => -1, 'signo_existencia' => -1, 'ocurrido_at' => now(), 'salida_at' => now(),
                'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $actor->id,
                'datos' => [...$c->datos, 'restaura_conciliacion_id' => $c->id, 'motivo' => 'Restauración de salida Romana al anular el despacho conciliado.'],
            ]);
        }
    }
}
