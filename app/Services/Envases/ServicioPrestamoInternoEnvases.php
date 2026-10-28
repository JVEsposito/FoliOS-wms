<?php

namespace App\Services\Envases;

use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use DateTimeInterface;
use Ramsey\Uuid\Uuid;

/**
 * Bins de la planta que completan una recepción. Cuando el campo manda más totes de los que
 * caben en sus bins (por ejemplo 31 totes sobre 1 bin, con 24 por bin), la planta agrega
 * bins propios para procesar la fruta y se los presta a la cuenta del cliente.
 *
 * El préstamo es interno: esos bins nunca salen de la planta. Al destare el camión se lleva
 * sus propios envases y el préstamo se devuelve sin movimiento físico. Ninguno de los dos
 * asientos cambia la existencia, y el efecto neto en la cuenta es 0.
 *
 * Se llama dentro de la transacción de Validación MP o del destare, con la recepción bloqueada.
 */
final class ServicioPrestamoInternoEnvases
{
    public function prestar(
        RecepcionRomana $recepcion,
        int $cantidad,
        User $usuario,
        DateTimeInterface $ocurrido,
        string $validacionMpId,
    ): ?MovimientoEnvase {
        if ($cantidad < 1) {
            return null;
        }

        return MovimientoEnvase::create([
            // Uno por recepción: Validación MP se confirma una sola vez.
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "prestamo-interno:{$recepcion->id}")->toString(),
            'temporada_id' => $recepcion->temporada_id,
            'cliente_id' => $recepcion->cliente_id,
            'recepcion_romana_id' => $recepcion->id,
            'documento_tipo' => 'recepcion_romana',
            'documento_id' => $recepcion->id,
            'numero_documento' => $recepcion->numero_guia_despacho,
            'tipo_movimiento' => TipoMovimientoEnvase::PrestamoInterno,
            'tipo_envase' => TipoEnvaseRomana::Bins,
            'cantidad' => $cantidad,
            'signo_cuenta' => -1,
            'signo_existencia' => 0,
            'propiedad' => PropiedadEnvase::Propia,
            'ocurrido_at' => $ocurrido,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
            'creado_por_user_id' => $usuario->id,
            'datos' => [
                'numero_recepcion' => $recepcion->numero_recepcion,
                'validacion_mp_id' => $validacionMpId,
            ],
        ]);
    }

    /** Devuelve el préstamo abierto de la recepción, si lo tiene. Repetirlo no duplica la devolución. */
    public function devolver(
        RecepcionRomana $recepcion,
        User $usuario,
        DateTimeInterface $ocurrido,
        ?string $numeroDocumento = null,
    ): ?MovimientoEnvase {
        $prestamo = $this->prestamo($recepcion);
        if (! $prestamo || MovimientoEnvase::query()->where('movimiento_origen_id', $prestamo->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::DevolucionPrestamoInterno->value)->exists()) {
            return null;
        }

        return MovimientoEnvase::create([
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "devolucion-prestamo-interno:{$recepcion->id}")->toString(),
            'temporada_id' => $prestamo->temporada_id,
            'cliente_id' => $prestamo->cliente_id,
            'recepcion_romana_id' => $recepcion->id,
            'documento_tipo' => 'recepcion_romana',
            'documento_id' => $recepcion->id,
            'numero_documento' => filled($numeroDocumento) ? $numeroDocumento : $prestamo->numero_documento,
            'tipo_movimiento' => TipoMovimientoEnvase::DevolucionPrestamoInterno,
            'tipo_envase' => $prestamo->tipo_envase,
            'cantidad' => $prestamo->cantidad,
            'signo_cuenta' => 1,
            'signo_existencia' => 0,
            'propiedad' => PropiedadEnvase::Propia,
            'movimiento_origen_id' => $prestamo->id,
            'ocurrido_at' => $ocurrido,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
            'creado_por_user_id' => $usuario->id,
            'datos' => ['numero_recepcion' => $recepcion->numero_recepcion],
        ]);
    }

    public function prestamo(RecepcionRomana $recepcion): ?MovimientoEnvase
    {
        return MovimientoEnvase::query()
            ->where('recepcion_romana_id', $recepcion->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::PrestamoInterno->value)
            ->first();
    }
}
