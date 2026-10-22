<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteHechos;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\Hecho;
use Carbon\CarbonImmutable;

/** Capacidad, arriendo o disponibilidad aprobada, con una cantidad por día civil. */
final class AcuerdosPeriodo implements FuenteHechos
{
    public const CLAVE = 'periodico';

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function nombre(): string
    {
        return 'Servicio acordado por período';
    }

    public function descripcion(): string
    {
        return 'Cantidad contratada por día, entre fechas inclusivas: arriendos, capacidad reservada, bodegaje o disponibilidad, con aprobación y respaldo.';
    }

    public function intervalo(): bool
    {
        return true;
    }

    public function medidas(): array
    {
        return ['cantidad' => ['nombre' => 'Cantidad contratada por día', 'unidad' => 'unidad']];
    }

    public function contexto(): array
    {
        return ['area' => 'servicios', 'dimensiones' => []];
    }

    public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable
    {
        foreach (CargoAcordado::query()->whereNotNull('aprobado_at')->whereNotNull('fecha_hasta')
            ->when($desde !== null, fn ($q) => $q->where('updated_at', '>=', $desde))->lazyById(500) as $cargo) {
            yield $this->hecho($cargo);
        }
    }

    public function porIds(ContextoCaptura $contexto, array $origenIds): iterable
    {
        foreach (array_chunk($origenIds, 500) as $ids) {
            foreach (CargoAcordado::query()->whereNotNull('aprobado_at')->whereNotNull('fecha_hasta')->whereIn('id', $ids)->get() as $cargo) {
                yield $this->hecho($cargo);
            }
        }
    }

    private function hecho(CargoAcordado $cargo): HechoCapturado
    {
        $zona = config('app.operational_timezone');

        return new HechoCapturado(origenId: $cargo->id, estado: $cargo->anulado_at === null ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
            ocurridoAt: CarbonImmutable::parse($cargo->fecha->toDateString(), $zona)->startOfDay()->utc(),
            terminaAt: CarbonImmutable::parse($cargo->fecha_hasta->toDateString(), $zona)->addDay()->startOfDay()->utc(),
            clienteId: $cargo->cliente_id, clienteNombre: $cargo->cliente_nombre, referencia: 'Acuerdo '.substr($cargo->id, 0, 8), medidas: ['cantidad' => (string) $cargo->cantidad],
            datos: ['concepto_declarado' => $cargo->servicio, 'base_cobro' => $cargo->base_cobro, 'estado_ejecucion' => $cargo->estado_ejecucion, 'respaldo' => $cargo->respaldo, 'cantidad_por_dia' => true]);
    }
}
