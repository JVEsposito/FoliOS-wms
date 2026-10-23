<?php

namespace App\Services\Materiales;

use App\Enums\EstadoRecepcionMaterial;
use App\Enums\TipoEventoRecepcionMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\FotoDocumentoRecepcionRequerida;
use App\Models\EventoRecepcionMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServicioRegularizacionRecepcionMaterial
{
    /** Completa el respaldo documental; no escribe en inventario, bultos ni reservas. */
    public function regularizar(RecepcionMaterial $recepcion, array $datos, User $usuario): RecepcionMaterial
    {
        Gate::forUser($usuario)->authorize('autorizar-recepciones-tardias-materiales');
        $canonico = function (mixed $valor) use (&$canonico): mixed {
            if (! is_array($valor)) {
                return $valor;
            }
            if (! array_is_list($valor)) {
                ksort($valor);
            }

            return array_map($canonico, $valor);
        };
        $hash = hash('sha256', json_encode($canonico($datos), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        try {
            return DB::transaction(function () use ($recepcion, $datos, $usuario, $hash): RecepcionMaterial {
                $recepcion = RecepcionMaterial::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();
                $evento = EventoRecepcionMaterial::where('operacion_id', $datos['operacion_id'])->first();
                if ($evento) {
                    return $this->repetir($evento, $recepcion, $usuario, $hash);
                }
                if ($recepcion->estado !== EstadoRecepcionMaterial::Confirmada || ! $recepcion->provisional || $recepcion->regularizado_at) {
                    throw new DomainException('Solo se regulariza una recepción provisional confirmada y pendiente.');
                }
                if ($recepcion->version !== (int) $datos['version_conocida']) {
                    throw new ConflictoOperacion('La recepción cambió desde la última lectura.');
                }
                if (app(ServicioTemporadaActiva::class)->obtener(bloquear: true)->id !== $recepcion->temporada_id) {
                    throw new DomainException('Solo se regularizan recepciones de la temporada activa.');
                }
                if ($datos['fecha_documento'] > now(config('app.operational_timezone'))->toDateString()) {
                    throw new DomainException('La fecha de emisión no puede ser futura.');
                }
                $fueraPlazo = $recepcion->fecha_limite_regularizacion?->isPast() ?? false;
                if ($fueraPlazo && ! ($datos['aceptar_fuera_plazo'] ?? false)) {
                    throw new DomainException('El plazo de cinco días corridos venció. Reconoce expresamente la regularización fuera de plazo.');
                }
                $fotos = $recepcion->fotos()->where('tipo', 'documento')->lockForUpdate()->get();
                if ($fotos->isEmpty()) {
                    throw new FotoDocumentoRecepcionRequerida;
                }
                $detalles = $recepcion->detalles()->with('item:id,codigo,nombre')->lockForUpdate()->get()->keyBy('id');
                $ids = collect($datos['detalles'])->pluck('id');
                if ($ids->duplicates()->isNotEmpty() || $ids->count() !== $detalles->count() || $ids->diff($detalles->keys())->isNotEmpty()) {
                    throw new DomainException('Incluye una vez cada detalle original de esta recepción, sin agregar ni eliminar productos.');
                }
                $representarDetalle = fn ($detalle): array => ['id' => $detalle->id, 'item' => $detalle->item?->only(['codigo', 'nombre']), 'unidad_medida' => $detalle->unidad_medida, 'cantidad_documental' => $detalle->cantidad_documental];
                $antes = ['numero_guia_despacho' => $recepcion->numero_guia_despacho, 'fecha_documento' => $recepcion->fecha_documento?->toDateString(), 'detalles' => $detalles->map($representarDetalle)->values()->all()];
                foreach ($datos['detalles'] as $linea) {
                    // La unidad base del detalle original es inmutable; no se vuelve a convertir el inventario.
                    $detalles[$linea['id']]->update(['cantidad_documental' => UnidadesMateriales::convertir($linea['cantidad_documental'], '1')]);
                }
                $version = $recepcion->version;
                $recepcion->update([
                    'numero_guia_despacho' => trim($datos['numero_guia_despacho']),
                    'fecha_documento' => $datos['fecha_documento'],
                    'regularizado_por_user_id' => $usuario->id,
                    'regularizado_at' => now(),
                    'motivo_regularizacion' => trim($datos['motivo']),
                    'version' => $version + 1,
                ]);
                EventoRecepcionMaterial::create([
                    'recepcion_material_id' => $recepcion->id,
                    'operacion_id' => $datos['operacion_id'],
                    'tipo' => TipoEventoRecepcionMaterial::Regularizada,
                    'datos' => ['payload_hash' => $hash, 'antes' => $antes, 'despues' => ['numero_guia_despacho' => $recepcion->numero_guia_despacho, 'fecha_documento' => $datos['fecha_documento'], 'detalles' => $detalles->map($representarDetalle)->values()->all()], 'version_anterior' => $version, 'version_resultante' => $version + 1, 'fuera_plazo' => $fueraPlazo, 'usuario' => $usuario->only(['id', 'name']), 'fotos_documento' => $fotos->map->only(['id', 'sha256'])->values()->all()],
                    'observacion' => trim($datos['motivo']), 'user_id' => $usuario->id, 'ocurrido_at' => now(),
                ]);

                return app(ServicioRecepcionMaterial::class)->cargar($recepcion->refresh());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $evento = EventoRecepcionMaterial::where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                return $this->repetir($evento, $recepcion->fresh(), $usuario, $hash);
            }
            throw new ConflictoOperacion('La regularización entró en conflicto con otra operación.', previous: $exception);
        }
    }

    private function repetir(EventoRecepcionMaterial $evento, RecepcionMaterial $recepcion, User $usuario, string $hash): RecepcionMaterial
    {
        if ($evento->recepcion_material_id !== $recepcion->id || $evento->tipo !== TipoEventoRecepcionMaterial::Regularizada || $evento->user_id !== $usuario->id || ! hash_equals((string) data_get($evento->datos, 'payload_hash', ''), $hash)) {
            throw new ConflictoOperacion('El UUID de regularización ya fue utilizado con datos diferentes.');
        }

        return app(ServicioRecepcionMaterial::class)->cargar($recepcion);
    }
}
