<?php

namespace App\Services\Materiales;

use App\Enums\EstadoRecepcionMaterial;
use App\Models\RecepcionMaterial;
use Carbon\CarbonImmutable;
use DomainException;

/** Fechas físicas separadas de las marcas del sistema. Plazo en días de calendario local. */
class RegistroRecepcionMaterial
{
    public function preparar(array $datos, ?RecepcionMaterial $anterior = null): array
    {
        $provisional = (bool) ($datos['provisional'] ?? $anterior?->provisional ?? false);
        $fecha = array_key_exists('fecha_recepcion', $datos)
            ? CarbonImmutable::parse($datos['fecha_recepcion'], config('app.operational_timezone'))->utc()
            : $anterior?->fecha_recepcion?->toImmutable();
        if (! $fecha && $provisional) {
            $fecha = CarbonImmutable::now();
        }
        if ($fecha?->isFuture()) {
            throw new DomainException('La fecha de recepción física no puede estar en el futuro.');
        }
        $sinCambio = $fecha && $anterior?->fecha_recepcion?->equalTo($fecha);
        $retroactivo = $sinCambio ? $anterior->registro_retroactivo : ($fecha && $fecha->setTimezone(config('app.operational_timezone'))->toDateString() < CarbonImmutable::now(config('app.operational_timezone'))->toDateString());
        $motivo = trim((string) ($datos['motivo_registro_tardio'] ?? $anterior?->motivo_registro_tardio ?? ''));
        if (($provisional || $retroactivo) && mb_strlen($motivo) < 5) {
            throw new DomainException('Indica el motivo del registro retroactivo o provisional (al menos 5 caracteres).');
        }

        return [
            'fecha_recepcion' => $fecha,
            'provisional' => $provisional,
            'registro_retroactivo' => (bool) $retroactivo,
            'motivo_registro_tardio' => $motivo ?: null,
            'fecha_limite_regularizacion' => $fecha ? $this->limite($fecha) : null,
        ];
    }

    public function limite(CarbonImmutable $fecha): CarbonImmutable
    {
        return $fecha->setTimezone(config('app.operational_timezone'))->startOfDay()->addDays(5)->endOfDay()->utc()->setMicrosecond(0);
    }

    public function representar(RecepcionMaterial $recepcion): array
    {
        $pendiente = $recepcion->provisional && $recepcion->estado === EstadoRecepcionMaterial::Confirmada && ! $recepcion->regularizado_at;
        $vencida = $pendiente && $recepcion->fecha_limite_regularizacion?->isPast();

        return [
            'provisional' => $recepcion->provisional,
            'retroactivo' => $recepcion->registro_retroactivo,
            'fecha_recepcion' => $recepcion->fecha_recepcion?->toAtomString(),
            'motivo' => $recepcion->motivo_registro_tardio,
            'fecha_limite' => $recepcion->fecha_limite_regularizacion?->toAtomString(),
            'zona_horaria' => config('app.operational_timezone'),
            'requiere_autorizacion' => $recepcion->provisional || $recepcion->registro_retroactivo,
            'estado' => $recepcion->regularizado_at ? 'regularizada' : ($vencida ? 'vencida' : ($pendiente ? 'pendiente' : ($recepcion->provisional && $recepcion->estado === EstadoRecepcionMaterial::Borrador ? 'por_autorizar' : 'no_requerida'))),
            'autorizado_at' => $recepcion->autorizado_at?->toAtomString(),
            'autorizado_por' => $recepcion->autorizadoPor?->only(['id', 'name']),
            'motivo_autorizacion' => $recepcion->motivo_autorizacion,
            'regularizado_at' => $recepcion->regularizado_at?->toAtomString(),
            'regularizado_por' => $recepcion->regularizadoPor?->only(['id', 'name']),
            'motivo_regularizacion' => $recepcion->motivo_regularizacion,
            'regularizada_fuera_plazo' => $recepcion->regularizado_at && $recepcion->fecha_limite_regularizacion && $recepcion->regularizado_at->gt($recepcion->fecha_limite_regularizacion),
        ];
    }
}
