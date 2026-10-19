<?php

namespace App\Finanzas\Http;

use App\Finanzas\Models\EventoTarifa;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\Tarifa;
use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\ClientesOperacion;
use App\Finanzas\Tarifario\Precio;

/** Forma JSON del tarifario para Oficina. */
final class PresentadorTarifas
{
    public function __construct(
        private readonly CatalogoServicios $servicios,
        private readonly ClientesOperacion $clientes,
    ) {}

    /**
     * @param  array<string, Moneda>  $monedas
     * @return array<string, mixed>
     */
    public function tarifa(Tarifa $tarifa, array $monedas, string $hoy): array
    {
        $moneda = $monedas[$tarifa->moneda] ?? null;

        return [
            'id' => $tarifa->id,
            'general' => $tarifa->general(),
            'cliente_id' => $tarifa->cliente_id,
            'cliente_nombre' => $this->nombreCliente($tarifa),
            'servicio' => $tarifa->servicio,
            'servicio_nombre' => $this->servicios->nombreServicio($tarifa->servicio),
            'unidad' => $tarifa->unidad,
            'unidad_nombre' => $this->servicios->nombreUnidad($tarifa->servicio, $tarifa->unidad),
            'moneda' => $tarifa->moneda,
            'moneda_simbolo' => $moneda?->simbolo ?? $tarifa->moneda,
            'precio' => Precio::normalizar((string) $tarifa->precio),
            'vigente_desde' => $tarifa->vigente_desde->toDateString(),
            'vigente_hasta' => $tarifa->vigente_hasta?->toDateString(),
            'estado' => $tarifa->estado($hoy),
            'nota' => $tarifa->nota,
            'anulada_at' => $tarifa->anulada_at?->toIso8601String(),
            'motivo_anulacion' => $tarifa->motivo_anulacion,
            'actualizada_por' => $tarifa->actualizada_por_nombre,
            'actualizada_at' => $tarifa->updated_at?->toIso8601String(),
        ];
    }

    /** El nombre actual del cliente en la operación; si ya no existe, el que tenía al crear el precio. */
    public function nombreCliente(Tarifa $tarifa): string
    {
        if ($tarifa->cliente_id === null) {
            return 'Tarifa general';
        }

        return $this->clientes->buscar($tarifa->cliente_id)['nombre'] ?? $tarifa->cliente_nombre ?? $tarifa->cliente_id;
    }

    /** @return array<string, mixed> */
    public function evento(EventoTarifa $evento): array
    {
        return [
            'id' => $evento->id,
            'accion' => $evento->accion,
            'cambios' => $evento->cambios,
            'motivo' => $evento->motivo,
            'usuario' => $evento->user_nombre,
            'fecha' => $evento->created_at->toIso8601String(),
        ];
    }

    /** @return array{codigo: string, nombre: string, simbolo: string, decimales: int, activa: bool} */
    public function moneda(Moneda $moneda): array
    {
        return [
            'codigo' => $moneda->codigo,
            'nombre' => $moneda->nombre,
            'simbolo' => $moneda->simbolo,
            'decimales' => $moneda->decimales,
            'activa' => $moneda->activa,
        ];
    }
}
