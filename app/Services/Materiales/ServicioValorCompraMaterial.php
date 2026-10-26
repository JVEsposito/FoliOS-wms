<?php

namespace App\Services\Materiales;

use App\Enums\EstadoRecepcionMaterial;
use App\Enums\TipoEventoRecepcionMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\EventoRecepcionMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServicioValorCompraMaterial
{
    /** Solo precios documentales: conserva cantidades, folios, reservas y snapshots físicos. */
    public function guardar(RecepcionMaterial $recepcion, array $datos, User $usuario): RecepcionMaterial
    {
        Gate::forUser($usuario)->authorize('valorizar-recepciones-materiales');
        $lineas = collect($datos['detalles'])->map(fn (array $linea): array => [
            'id' => $linea['id'],
            'precio_unitario_neto' => $linea['precio_unitario_neto'] === null ? null : (string) BigDecimal::of((string) $linea['precio_unitario_neto'])->toScale(6),
            'moneda' => $linea['moneda'],
        ])->sortBy('id')->values()->all();
        $hash = hash('sha256', json_encode([$lineas, (int) $datos['version_conocida'], trim($datos['motivo'])], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($recepcion, $datos, $usuario, $lineas, $hash): RecepcionMaterial {
                $recepcion = RecepcionMaterial::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();
                $evento = EventoRecepcionMaterial::where('operacion_id', $datos['operacion_id'])->first();
                if ($evento) {
                    return $this->repetir($evento, $recepcion, $usuario, $hash);
                }
                if ($recepcion->estado !== EstadoRecepcionMaterial::Confirmada) {
                    throw new DomainException('Solo se valorizan recepciones confirmadas.');
                }
                if ($recepcion->version !== (int) $datos['version_conocida']) {
                    throw new ConflictoOperacion('La recepción cambió. Recarga y revisa los valores antes de guardar.');
                }
                if (app(ServicioTemporadaActiva::class)->obtener(bloquear: true)->id !== $recepcion->temporada_id) {
                    throw new DomainException('Solo se modifican precios de la temporada activa; los históricos conservan su consulta.');
                }
                $detalles = $recepcion->detalles()->with('item:id,codigo,nombre')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $ids = collect($lineas)->pluck('id');
                if ($ids->duplicates()->isNotEmpty() || $ids->diff($detalles->keys())->isNotEmpty()) {
                    throw new DomainException('Cada línea debe pertenecer a esta recepción y aparecer una sola vez.');
                }
                $antes = [];
                $despues = [];
                foreach ($lineas as $linea) {
                    $detalle = $detalles[$linea['id']];
                    if (($linea['precio_unitario_neto'] === null) !== ($linea['moneda'] === null)) {
                        throw new DomainException('Indica precio y moneda juntos, o deja ambos pendientes.');
                    }
                    if (BigDecimal::of($detalle->cantidad_recibida)->isZero() && $linea['precio_unitario_neto'] !== null) {
                        throw new DomainException('No se valorizan cantidades rechazadas ni líneas sin cantidad aceptada.');
                    }
                    $identidad = ['id' => $detalle->id, 'item' => $detalle->item?->only(['codigo', 'nombre'])];
                    $antes[] = $identidad + ValorCompraMaterial::representar($detalle);
                    $detalle->update(['precio_unitario_compra' => $linea['precio_unitario_neto'], 'moneda_compra' => $linea['moneda']]);
                    $despues[] = $identidad + ValorCompraMaterial::representar($detalle);
                }
                $version = $recepcion->version;
                $recepcion->update(['version' => $version + 1]);
                EventoRecepcionMaterial::create([
                    'recepcion_material_id' => $recepcion->id, 'operacion_id' => $datos['operacion_id'],
                    'tipo' => TipoEventoRecepcionMaterial::Valorizada,
                    'datos' => ['payload_hash' => $hash, 'antes' => $antes, 'despues' => $despues, 'version_anterior' => $version, 'version_resultante' => $version + 1, 'usuario' => $usuario->only(['id', 'name'])],
                    'observacion' => trim($datos['motivo']), 'user_id' => $usuario->id, 'ocurrido_at' => now(),
                ]);

                return app(ServicioRecepcionMaterial::class)->cargar($recepcion->refresh());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $evento = EventoRecepcionMaterial::where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                return $this->repetir($evento, $recepcion->fresh(), $usuario, $hash);
            }
            throw new ConflictoOperacion('La valorización entró en conflicto con otra operación.', previous: $exception);
        }
    }

    private function repetir(EventoRecepcionMaterial $evento, RecepcionMaterial $recepcion, User $usuario, string $hash): RecepcionMaterial
    {
        if ($evento->recepcion_material_id !== $recepcion->id || $evento->tipo !== TipoEventoRecepcionMaterial::Valorizada || $evento->user_id !== $usuario->id || ! hash_equals((string) data_get($evento->datos, 'payload_hash', ''), $hash)) {
            throw new ConflictoOperacion('El UUID de valorización ya fue utilizado con datos diferentes.');
        }

        return app(ServicioRecepcionMaterial::class)->cargar($recepcion);
    }
}
