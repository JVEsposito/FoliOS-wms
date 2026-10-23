<?php

namespace App\Services\Materiales;

use App\Models\OperacionRetiroMaterial;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;

/** El plazo pertenece a cada entrega física, también cuando la orden es parcial. */
class RegistroDespachoMaterial
{
    public function preparar(array $datos, User $usuario): array
    {
        if ($datos === []) {
            return [];
        }
        $fecha = isset($datos['fecha_despacho'])
            ? CarbonImmutable::parse($datos['fecha_despacho'], config('app.operational_timezone'))->utc()
            : CarbonImmutable::now();
        if ($fecha->isFuture()) {
            throw new DomainException('La fecha de entrega física no puede estar en el futuro.');
        }
        $provisional = (bool) ($datos['provisional'] ?? false);
        $retroactivo = $fecha->setTimezone(config('app.operational_timezone'))->toDateString() < CarbonImmutable::now(config('app.operational_timezone'))->toDateString();
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        $autorizacion = trim((string) ($datos['motivo_autorizacion'] ?? ''));
        $documento = trim((string) ($datos['numero_documento'] ?? ''));
        $documento = $documento === '' ? null : $documento;
        $emision = $datos['fecha_documento'] ?? null;
        $this->validarDocumento($documento, $emision);
        $limite = app(RegistroRecepcionMaterial::class)->limite($fecha);
        if ($provisional || $retroactivo) {
            Gate::forUser($usuario)->authorize('autorizar-despachos-tardios-materiales');
            if (mb_strlen($motivo) < 5 || mb_strlen($autorizacion) < 5) {
                throw new DomainException('Indica el motivo del registro y de su autorización (al menos 5 caracteres cada uno).');
            }
            if ($limite->isPast() && ! ($datos['aceptar_fuera_plazo'] ?? false)) {
                throw new DomainException('Venció el plazo de cinco días corridos. Reconoce expresamente la autorización fuera de plazo.');
            }
        }

        return [
            'fecha_despacho' => $fecha, 'provisional' => $provisional, 'registro_retroactivo' => $retroactivo,
            'numero_documento' => $documento, 'fecha_documento' => $emision,
            'motivo_registro_tardio' => $motivo ?: null, 'fecha_limite_regularizacion' => $limite,
            'autorizado_por_user_id' => $provisional || $retroactivo ? $usuario->id : null,
            'autorizado_at' => $provisional || $retroactivo ? now() : null,
            'motivo_autorizacion' => $provisional || $retroactivo ? $autorizacion : null,
            'snapshot_registro' => ['fecha_despacho' => $fecha->toAtomString(), 'provisional' => $provisional,
                'retroactivo' => $retroactivo, 'numero_documento' => $documento, 'fecha_documento' => $emision,
                'motivo' => $motivo ?: null, 'motivo_autorizacion' => $autorizacion ?: null,
                'fuera_plazo' => $limite->isPast(), 'registrado_por' => $usuario->only(['id', 'name']), 'registrado_at' => now()->toAtomString()],
        ];
    }

    public function validarDocumento(?string $documento, ?string $emision): void
    {
        if (($documento !== null && $documento !== '') !== ($emision !== null && $emision !== '')) {
            throw new DomainException('Completa juntos el número del documento y su fecha de emisión.');
        }
        if ($emision && $emision > now(config('app.operational_timezone'))->toDateString()) {
            throw new DomainException('La fecha de emisión no puede ser futura.');
        }
    }

    public function representar(OperacionRetiroMaterial $entrega): array
    {
        $pendiente = $entrega->provisional && ! $entrega->regularizado_at;
        $vencida = $pendiente && $entrega->fecha_limite_regularizacion?->isPast();

        return [
            'id' => $entrega->id, 'version' => $entrega->version, 'provisional' => $entrega->provisional,
            'retroactivo' => $entrega->registro_retroactivo, 'fecha_despacho' => $entrega->fecha_despacho?->toAtomString(),
            'numero_documento' => $entrega->numero_documento, 'fecha_documento' => $entrega->fecha_documento?->toDateString(),
            'motivo' => $entrega->motivo_registro_tardio, 'fecha_limite' => $entrega->fecha_limite_regularizacion?->toAtomString(),
            'zona_horaria' => config('app.operational_timezone'), 'estado' => $entrega->regularizado_at ? 'regularizada' : ($vencida ? 'vencida' : ($pendiente ? 'pendiente' : 'no_requerida')),
            'autorizado_por' => $entrega->autorizadoPor?->only(['id', 'name']), 'autorizado_at' => $entrega->autorizado_at?->toAtomString(),
            'motivo_autorizacion' => $entrega->motivo_autorizacion,
            'regularizado_por' => $entrega->regularizadoPor?->only(['id', 'name']), 'regularizado_at' => $entrega->regularizado_at?->toAtomString(),
            'motivo_regularizacion' => $entrega->motivo_regularizacion,
            'regularizada_fuera_plazo' => $entrega->regularizado_at && $entrega->fecha_limite_regularizacion && $entrega->regularizado_at->gt($entrega->fecha_limite_regularizacion),
            'registrado_por' => $entrega->usuario?->only(['id', 'name']), 'registrado_at' => $entrega->procesada_at?->toAtomString(),
            'original' => $entrega->snapshot_registro,
        ];
    }
}
