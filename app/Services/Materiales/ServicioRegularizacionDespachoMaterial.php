<?php

namespace App\Services\Materiales;

use App\Exceptions\ConflictoOperacion;
use App\Models\DespachoMaterial;
use App\Models\OperacionRetiroMaterial;
use App\Models\RegularizacionDespachoMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServicioRegularizacionDespachoMaterial
{
    /** No modifica saldos, reservas, retiros, cantidades entregadas ni movimientos. */
    public function regularizar(DespachoMaterial $despacho, string $entregaId, array $datos, User $usuario): DespachoMaterial
    {
        Gate::forUser($usuario)->authorize('autorizar-despachos-tardios-materiales');
        ksort($datos);
        $hash = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($despacho, $entregaId, $datos, $usuario, $hash): DespachoMaterial {
                // Mismo orden de bloqueo que la entrega física: solicitud y luego operación.
                $despacho = DespachoMaterial::whereKey($despacho->id)->lockForUpdate()->firstOrFail();
                $entrega = OperacionRetiroMaterial::where('despacho_material_id', $despacho->id)->whereKey($entregaId)->lockForUpdate()->firstOrFail();
                $anterior = RegularizacionDespachoMaterial::find($datos['operacion_id']);
                if ($anterior) {
                    return $this->repetir($anterior, $despacho, $entregaId, $usuario, $hash);
                }
                if (! $entrega->procesada_at || ! $entrega->provisional || $entrega->regularizado_at) {
                    throw new DomainException('Solo se regulariza una entrega provisional ejecutada y pendiente.');
                }
                if ($entrega->version !== (int) $datos['version_conocida']) {
                    throw new ConflictoOperacion('La entrega cambió desde la última lectura.');
                }
                if (app(ServicioTemporadaActiva::class)->obtener(bloquear: true)->id !== $despacho->temporada_id) {
                    throw new DomainException('Solo se regularizan despachos de la temporada activa.');
                }
                app(RegistroDespachoMaterial::class)->validarDocumento($datos['numero_documento'], $datos['fecha_documento']);
                $fueraPlazo = $entrega->fecha_limite_regularizacion?->isPast() ?? false;
                if ($fueraPlazo && ! ($datos['aceptar_fuera_plazo'] ?? false)) {
                    throw new DomainException('Venció el plazo de cinco días corridos. Reconoce expresamente la regularización fuera de plazo.');
                }
                $antes = ['numero_documento' => $entrega->numero_documento, 'fecha_documento' => $entrega->fecha_documento?->toDateString()];
                $version = $entrega->version;
                $entrega->update([
                    'numero_documento' => $datos['numero_documento'], 'fecha_documento' => $datos['fecha_documento'],
                    'regularizado_por_user_id' => $usuario->id, 'regularizado_at' => now(),
                    'motivo_regularizacion' => $datos['motivo'], 'version' => $version + 1,
                ]);
                RegularizacionDespachoMaterial::create([
                    'id' => $datos['operacion_id'], 'operacion_retiro_material_id' => $entrega->id, 'user_id' => $usuario->id,
                    'payload_hash' => $hash, 'antes' => $antes, 'despues' => ['numero_documento' => $entrega->numero_documento, 'fecha_documento' => $entrega->fecha_documento?->toDateString()],
                    'motivo' => $datos['motivo'], 'fuera_plazo' => $fueraPlazo,
                    'version_anterior' => $version, 'version_resultante' => $version + 1, 'ocurrido_at' => now(),
                ]);

                return app(ServicioDespachoMaterial::class)->cargar($despacho);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $anterior = RegularizacionDespachoMaterial::find($datos['operacion_id']);
            if ($anterior) {
                return $this->repetir($anterior, $despacho, $entregaId, $usuario, $hash);
            }
            throw new ConflictoOperacion('La regularización entró en conflicto con otra operación.', previous: $exception);
        }
    }

    private function repetir(RegularizacionDespachoMaterial $evento, DespachoMaterial $despacho, string $entregaId, User $usuario, string $hash): DespachoMaterial
    {
        if ($evento->operacion_retiro_material_id !== $entregaId || $evento->user_id !== $usuario->id || ! hash_equals($evento->payload_hash, $hash)) {
            throw new ConflictoOperacion('El UUID de regularización ya fue utilizado con datos diferentes.');
        }

        return app(ServicioDespachoMaterial::class)->cargar($despacho);
    }
}
