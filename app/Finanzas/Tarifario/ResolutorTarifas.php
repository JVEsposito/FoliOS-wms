<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\Models\Tarifa;

/**
 * Qué precios se cobran a un cliente por un servicio en un día. Un cliente con algún precio
 * propio para ese servicio, ese día, usa solo los suyos; si no tiene, usa la tarifa general.
 * La prefactura lo consulta día a día (el almacenaje se cobra por día o fracción).
 */
final class ResolutorTarifas
{
    /** @param  array<string, list<Tarifa>>  $porServicio */
    private function __construct(private readonly array $porServicio) {}

    /** Precios no anulados que rigen al menos un día entre dos fechas «Y-m-d» (inclusivas). */
    public static function entre(string $desde, string $hasta): self
    {
        $tarifas = Tarifa::query()
            ->whereNull('anulada_at')
            ->where('vigente_desde', '<=', $hasta)
            ->where(fn ($consulta) => $consulta->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $desde))
            ->orderBy('vigente_desde')
            ->orderBy('id')
            ->get();

        return new self($tarifas->groupBy('servicio')->map(fn ($grupo): array => $grupo->values()->all())->all());
    }

    public static function delDia(string $fecha): self
    {
        return self::entre($fecha, $fecha);
    }

    /**
     * Uno por unidad de cobro. Un cliente sin identificar (nulo) recibe la tarifa general.
     *
     * @return list<Tarifa>
     */
    public function aplicables(?string $clienteId, string $servicio, string $fecha): array
    {
        $delDia = array_values(array_filter(
            $this->porServicio[$servicio] ?? [],
            fn (Tarifa $tarifa): bool => $tarifa->cubre($fecha),
        ));

        if ($clienteId !== null) {
            $propios = array_values(array_filter($delDia, fn (Tarifa $tarifa): bool => $tarifa->cliente_id === $clienteId));
            if ($propios !== []) {
                return $propios;
            }
        }

        return array_values(array_filter($delDia, fn (Tarifa $tarifa): bool => $tarifa->general()));
    }
}
