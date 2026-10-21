<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteHechos;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\Hecho;
use Carbon\CarbonImmutable;

/** Evidencia económica aprobada, independiente del estado de ejecución de la operación. */
final class CargosDeclarados implements FuenteHechos
{
    public const CLAVE = 'declarado';

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function nombre(): string
    {
        return 'Cargos acordados y manuales';
    }

    public function descripcion(): string
    {
        return 'Cantidades aprobadas por ejecución, acuerdo, disponibilidad o compromiso, aunque no se haya realizado el proceso.';
    }

    public function intervalo(): bool
    {
        return false;
    }

    public function medidas(): array
    {
        return ['cantidad' => ['nombre' => 'Cantidad acordada', 'unidad' => 'unidad']];
    }

    public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable
    {
        foreach (CargoAcordado::query()->whereNotNull('aprobado_at')
            ->when($desde !== null, fn ($q) => $q->where('updated_at', '>=', $desde))->lazyById(500) as $cargo) {
            yield $this->hecho($cargo);
        }
    }

    public function porIds(ContextoCaptura $contexto, array $origenIds): iterable
    {
        foreach (array_chunk($origenIds, 500) as $ids) {
            foreach (CargoAcordado::query()->whereNotNull('aprobado_at')->whereIn('id', $ids)->get() as $cargo) {
                yield $this->hecho($cargo);
            }
        }
    }

    private function hecho(CargoAcordado $cargo): HechoCapturado
    {
        return new HechoCapturado(
            origenId: $cargo->id,
            estado: $cargo->anulado_at === null ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
            ocurridoAt: CarbonImmutable::parse($cargo->fecha->toDateString().' 12:00', config('app.operational_timezone'))->utc(),
            clienteId: $cargo->cliente_id,
            clienteNombre: $cargo->cliente_nombre,
            referencia: 'Cargo '.substr($cargo->id, 0, 8),
            medidas: ['cantidad' => (string) $cargo->cantidad],
            datos: ['concepto_declarado' => $cargo->servicio, 'base_cobro' => $cargo->base_cobro,
                'estado_ejecucion' => $cargo->estado_ejecucion, 'respaldo' => $cargo->respaldo, 'contexto' => $cargo->contexto],
        );
    }
}
