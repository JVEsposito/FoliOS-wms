<?php

namespace App\Finanzas\Prefactura;

use App\Finanzas\Cierre\CalendarioCierres;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\AporteCierre;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\LineaCierre;
use App\Finanzas\Periodo;
use App\Finanzas\Tarifario\CatalogoServicios;

/**
 * La prefactura de un mes como se consulta: congelada si el mes está cerrado, calculada si
 * está abierto, y en ambos casos con los ajustes que se cobran en él (diferencias aceptadas de
 * meses cerrados antes).
 */
final class LibroPrefactura
{
    public function __construct(
        private readonly EsquemaFinanzas $esquema,
        private readonly CalculadoraPrefactura $calculadora,
        private readonly CalendarioCierres $calendario,
        private readonly CatalogoServicios $servicios,
    ) {}

    /** @return array<string, mixed> */
    public function mes(Periodo $periodo): array
    {
        $mes = $periodo->clave();
        $cierre = $this->calendario->vigente($mes);
        $base = $cierre === null
            // Una sola foto de la base: un hecho que se corrige a mitad del cálculo no cuenta doble.
            ? $this->esquema->transaccion(fn (): array => $this->calculadora->calcular($periodo))
            : $this->congelado($periodo, $cierre);
        $libro = $this->conAjustes($base, $this->ajustes($mes, abierto: $cierre === null));
        $impedimento = $cierre === null ? $this->calendario->impedimento($mes) : $this->calendario->impedimentoReapertura($mes);

        return [
            ...$libro,
            'estado' => $cierre === null ? 'abierto' : 'cerrado',
            'cierre' => $cierre === null ? null : $this->cierre($cierre),
            'reaperturas' => Cierre::query()->where('mes', $mes)->where('estado', Cierre::ESTADO_REABIERTO)->orderByDesc('version')->get()
                ->map(fn (Cierre $anterior): array => $this->cierre($anterior))->all(),
            'para_cerrar' => $cierre === null ? ['posible' => $impedimento === null, 'motivo' => $impedimento] : null,
            'para_reabrir' => $cierre === null ? null : ['posible' => $impedimento === null, 'motivo' => $impedimento],
            'primer_mes_abierto' => $this->calendario->primerMesAbierto(),
        ];
    }

    /**
     * Un cliente con los hechos de cada línea. Con $tarifa, solo el detalle de esa línea: un mes de
     * almacenaje pueden ser miles de estadías.
     *
     * @return array<string, mixed>|null
     */
    public function detalle(Periodo $periodo, string $cliente, ?int $tarifa): ?array
    {
        $cierre = $this->calendario->vigente($periodo->clave());
        if ($cierre === null) {
            $datos = $this->calculadora->calcular($periodo, $cliente, conDetalle: true)['clientes'][0] ?? null;
            if ($datos !== null && $tarifa !== null) {
                $datos['lineas'] = array_map(fn (array $linea): array => $linea['tarifa_id'] === $tarifa ? $linea : [...$linea, 'detalle' => []], $datos['lineas']);
            }

            return $datos;
        }

        $lineas = LineaCierre::query()->where('cierre_id', $cierre->id)->where('cliente_id', $cliente)->orderBy('id')->get();
        if ($lineas->isEmpty()) {
            return null;
        }
        $aportes = AporteCierre::query()->where('cierre_id', $cierre->id)->where('cliente_id', $cliente)
            ->when($tarifa !== null, fn ($consulta) => $consulta->where('tarifa_id', $tarifa))
            ->orderBy('desde')->orderBy('referencia')->orderBy('id')
            ->get()
            ->groupBy('tarifa_id');

        return [
            'cliente_id' => $cliente,
            'cliente_nombre' => $lineas->first()->cliente_nombre,
            'lineas' => $lineas->map(fn (LineaCierre $linea): array => [
                ...$this->linea($linea),
                'detalle' => ($aportes[$linea->tarifa_id] ?? collect())->map(fn (AporteCierre $aporte): array => [
                    'hecho_id' => $aporte->hecho_id,
                    'referencia' => $aporte->referencia,
                    'desde' => $aporte->desde->toDateString(),
                    'hasta' => $aporte->hasta->toDateString(),
                    'dias' => $aporte->dias,
                    'cantidad' => Montos::texto($aporte->cantidad),
                ])->values()->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function cierre(Cierre $cierre): array
    {
        return [
            'id' => $cierre->id,
            'mes' => $cierre->mes,
            'version' => $cierre->version,
            'estado' => $cierre->estado,
            'cerrado_at' => $cierre->cerrado_at->toIso8601String(),
            'cerrado_por' => $cierre->cerrado_por_nombre,
            'lectura_at' => $cierre->lectura_at?->toIso8601String(),
            'reabierto_at' => $cierre->reabierto_at?->toIso8601String(),
            'reabierto_por' => $cierre->reabierto_por_nombre,
            'motivo_reapertura' => $cierre->motivo_reapertura,
            'totales' => $cierre->totales,
        ];
    }

    /**
     * El mes tal como quedó al cerrarlo, con la misma forma que la prefactura calculada.
     *
     * @return array<string, mixed>
     */
    private function congelado(Periodo $periodo, Cierre $cierre): array
    {
        $clientes = [];
        foreach (LineaCierre::query()->where('cierre_id', $cierre->id)->orderBy('id')->get() as $linea) {
            $clientes[$linea->cliente_id] ??= $this->clienteVacio($linea->cliente_id, $linea->cliente_nombre);
            $clientes[$linea->cliente_id]['lineas'][] = $this->linea($linea);
        }
        foreach ($cierre->pendientes['sin_precio'] ?? [] as $pendiente) {
            $clientes[$pendiente['cliente_id']] ??= $this->clienteVacio($pendiente['cliente_id'], $pendiente['cliente_nombre']);
            $clientes[$pendiente['cliente_id']]['sin_precio'] = $pendiente['servicios'];
        }

        return [
            'periodo' => $periodo->aArreglo(),
            'corte' => $periodo->fin->toDateString(),
            'a_la_fecha' => false,
            'clientes' => array_values($clientes),
            'totales' => [],
            'sin_cliente' => $cierre->pendientes['sin_cliente'] ?? [],
            'resumen' => [
                'clientes' => (int) ($cierre->resumen['clientes'] ?? 0),
                'hechos_valorizados' => (int) ($cierre->resumen['hechos_valorizados'] ?? 0),
                'hechos_sin_precio' => (int) ($cierre->resumen['hechos_sin_precio'] ?? 0),
                'hechos_sin_cliente' => (int) ($cierre->resumen['hechos_sin_cliente'] ?? 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function clienteVacio(string $id, string $nombre): array
    {
        return ['cliente_id' => $id, 'cliente_nombre' => $nombre, 'totales' => [], 'lineas' => [], 'sin_precio' => []];
    }

    /** @return array<string, mixed> */
    private function linea(LineaCierre $linea): array
    {
        return [
            'servicio' => $linea->servicio,
            'servicio_nombre' => $this->servicios->nombreServicio($linea->servicio),
            'unidad' => $linea->unidad,
            'unidad_nombre' => $this->servicios->nombreUnidad($linea->servicio, $linea->unidad),
            'tarifa_id' => $linea->tarifa_id,
            'origen' => $linea->origen,
            'precio' => Montos::texto($linea->precio),
            'moneda' => $linea->moneda,
            'simbolo' => $linea->simbolo,
            'decimales' => $linea->decimales,
            'vigente_desde' => $linea->vigente_desde->toDateString(),
            'vigente_hasta' => $linea->vigente_hasta?->toDateString(),
            'cantidad' => Montos::texto($linea->cantidad),
            'dias' => $linea->dias,
            'hechos' => $linea->hechos,
            'monto' => Montos::enMoneda($linea->monto, $linea->decimales),
        ];
    }

    /**
     * Diferencias aceptadas que se cobran (o abonan) en este mes.
     *
     * @return list<array<string, mixed>>
     */
    private function ajustes(string $mes, bool $abierto): array
    {
        return Diferencia::query()
            ->where('mes_destino', $mes)
            ->where('decision', Diferencia::ACEPTADA)
            ->whereNull('anulada_at')
            ->orderBy('mes')
            ->orderBy('id')
            ->get()
            ->map(fn (Diferencia $ajuste): array => [
                'id' => $ajuste->id,
                'cliente_id' => $ajuste->cliente_id,
                'cliente_nombre' => $ajuste->cliente_nombre,
                'mes_origen' => $ajuste->mes,
                'servicio' => $ajuste->servicio,
                'servicio_nombre' => $this->servicios->nombreServicio($ajuste->servicio),
                'unidad' => $ajuste->unidad,
                'unidad_nombre' => $this->servicios->nombreUnidad($ajuste->servicio, $ajuste->unidad),
                'tarifa_id' => $ajuste->tarifa_id,
                'moneda' => $ajuste->moneda,
                'simbolo' => $ajuste->simbolo,
                'decimales' => $ajuste->decimales,
                'precio' => $ajuste->precio === null ? null : Montos::texto($ajuste->precio),
                'cantidad' => Montos::texto($ajuste->cantidad),
                'dias' => $ajuste->dias,
                'monto' => Montos::enMoneda($ajuste->monto, $ajuste->decimales),
                'motivo' => $ajuste->motivo,
                'decidida_at' => $ajuste->decidida_at->toIso8601String(),
                'decidida_por' => $ajuste->decidida_por_nombre,
                'puede_deshacerse' => $abierto,
            ])
            ->all();
    }

    /**
     * Agrega los ajustes a sus clientes y rehace los totales (líneas más ajustes, por moneda).
     *
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $ajustes
     * @return array<string, mixed>
     */
    private function conAjustes(array $base, array $ajustes): array
    {
        $clientes = [];
        foreach ($base['clientes'] as $cliente) {
            $clientes[$cliente['cliente_id']] = [...$cliente, 'ajustes' => []];
        }
        foreach ($ajustes as $ajuste) {
            $clientes[$ajuste['cliente_id']] ??= [...$this->clienteVacio($ajuste['cliente_id'], $ajuste['cliente_nombre']), 'ajustes' => []];
            $clientes[$ajuste['cliente_id']]['ajustes'][] = $ajuste;
        }

        $todos = [];
        foreach ($clientes as $id => $cliente) {
            $montos = [...$cliente['lineas'], ...$cliente['ajustes']];
            $clientes[$id]['totales'] = Montos::totales($montos);
            array_push($todos, ...$montos);
        }
        $lista = array_values($clientes);
        usort($lista, fn (array $a, array $b): int => strcasecmp($a['cliente_nombre'], $b['cliente_nombre']));

        return [
            ...$base,
            'clientes' => $lista,
            'totales' => Montos::totales($todos),
            'resumen' => [
                ...$base['resumen'],
                'clientes' => count(array_filter($lista, fn (array $cliente): bool => $cliente['lineas'] !== [] || $cliente['ajustes'] !== [])),
                'ajustes' => count($ajustes),
            ],
        ];
    }
}
