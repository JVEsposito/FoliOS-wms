<?php

namespace App\Finanzas\Cierre;

use App\Finanzas\Models\AporteCierre;
use App\Finanzas\Models\AporteDiferencia;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\LineaCierre;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Prefactura\Montos;
use App\Finanzas\Tarifario\CatalogoServicios;
use Brick\Math\BigDecimal;

/**
 * Qué cambió en un mes cerrado. Se recalcula el mes con los hechos y precios de hoy y se
 * compara, línea por línea (cliente, precio y moneda), con lo que se cobró: lo congelado al
 * cerrar más lo que ya se decidió sobre diferencias anteriores. Cada diferencia trae los hechos
 * que la explican (nuevos, corregidos o retirados) y, si cambió, el precio.
 *
 * Lo congelado por hecho se guarda compacto («cantidad|días|origen») y se va descartando a
 * medida que el recálculo lo encuentra igual: un mes con decenas de miles de estadías se compara
 * sin cargar su detalle completo en memoria.
 */
final class DiferenciasCierre
{
    public function __construct(
        private readonly CalculadoraPrefactura $calculadora,
        private readonly CatalogoServicios $servicios,
    ) {}

    public static function clave(string $cliente, int $tarifa, string $moneda): string
    {
        return "{$cliente}|{$tarifa}|{$moneda}";
    }

    /**
     * @return array{diferencias: list<array<string, mixed>>, resumen: array{diferencias: int, montos: list<array<string, mixed>>}}
     */
    public function calcular(Cierre $cierre): array
    {
        [$lineas, $hechos] = $this->cobrado($cierre);
        $cambios = [];

        $actual = $this->calculadora->calcular(Periodo::mes($cierre->mes), alAportar: function (array $aporte) use (&$hechos, &$cambios): void {
            $linea = self::clave($aporte['cliente_id'], $aporte['tarifa_id'], $aporte['moneda']);
            $hecho = $aporte['fuente'].'|'.$aporte['origen_id'];
            $previo = isset($hechos[$linea][$hecho]) ? explode('|', $hechos[$linea][$hecho], 3) : null;
            unset($hechos[$linea][$hecho]);

            $cantidad = Montos::texto($aporte['cantidad']);
            if ($previo !== null && $previo[0] === $cantidad && (int) $previo[1] === $aporte['dias']) {
                return;
            }
            $antes = $previo === null ? BigDecimal::zero() : BigDecimal::of($previo[0]);
            $cambios[$linea][$hecho] = [
                'fuente' => $aporte['fuente'],
                'origen_id' => $aporte['origen_id'],
                'hecho_id' => $aporte['hecho_id'],
                'referencia' => $aporte['referencia'],
                'desde' => $aporte['desde'],
                'hasta' => $aporte['hasta'],
                'tipo' => $antes->isZero() && (int) ($previo[1] ?? 0) === 0 ? AporteDiferencia::NUEVO : AporteDiferencia::CORREGIDO,
                'cantidad' => Montos::texto(BigDecimal::of($cantidad)->minus($antes)),
                'dias' => $aporte['dias'] - (int) ($previo[1] ?? 0),
            ];
        });

        // Lo cobrado que el recálculo ya no encontró en su línea: dejó de cobrarse ahí.
        $retirados = [];
        foreach ($hechos as $linea => $porHecho) {
            foreach ($porHecho as $hecho => $valor) {
                [$cantidad, $dias, $origen] = explode('|', $valor, 3);
                if (BigDecimal::of($cantidad)->isZero() && (int) $dias === 0) {
                    continue;
                }
                [$fuente, $origenId] = explode('|', $hecho, 2);
                $cambios[$linea][$hecho] = [
                    'fuente' => $fuente,
                    'origen_id' => $origenId,
                    'hecho_id' => null,
                    'referencia' => null,
                    'desde' => null,
                    'hasta' => null,
                    'tipo' => AporteDiferencia::RETIRADO,
                    'cantidad' => Montos::texto(BigDecimal::of($cantidad)->negated()),
                    'dias' => -(int) $dias,
                ];
                $retirados[$origen] = [$linea, $hecho];
            }
        }
        $this->completarRetirados($cambios, $retirados);

        $actuales = [];
        foreach ($actual['clientes'] as $cliente) {
            foreach ($cliente['lineas'] as $linea) {
                $actuales[self::clave($cliente['cliente_id'], (int) $linea['tarifa_id'], $linea['moneda'])] = [...$linea, 'cliente_id' => $cliente['cliente_id'], 'cliente_nombre' => $cliente['cliente_nombre']];
            }
        }

        $diferencias = [];
        foreach (array_unique([...array_keys($lineas), ...array_keys($actuales)]) as $clave) {
            $diferencia = $this->comparar($clave, $lineas[$clave] ?? null, $actuales[$clave] ?? null, $cambios[$clave] ?? []);
            if ($diferencia !== null) {
                $diferencias[] = $diferencia;
            }
        }
        usort($diferencias, fn (array $a, array $b): int => [mb_strtolower($a['cliente_nombre']), $this->servicios->orden($a['servicio'], $a['unidad']), $a['tarifa_id']]
            <=> [mb_strtolower($b['cliente_nombre']), $this->servicios->orden($b['servicio'], $b['unidad']), $b['tarifa_id']]);

        return [
            'diferencias' => $diferencias,
            'resumen' => [
                'diferencias' => count($diferencias),
                'montos' => Montos::totales(array_map(fn (array $diferencia): array => [
                    'moneda' => $diferencia['moneda'],
                    'simbolo' => $diferencia['simbolo'],
                    'decimales' => $diferencia['decimales'],
                    'monto' => $diferencia['diferencia']['monto'],
                ], $diferencias)),
            ],
        ];
    }

    /**
     * Lo que ya se cobró: por línea (cantidad, días y monto), y por hecho en forma compacta.
     * Ambas cosas suman lo congelado al cerrar y las decisiones vigentes sobre diferencias.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, string>>}
     */
    private function cobrado(Cierre $cierre): array
    {
        $lineas = [];
        foreach (LineaCierre::query()->where('cierre_id', $cierre->id)->get() as $linea) {
            $lineas[self::clave($linea->cliente_id, $linea->tarifa_id, $linea->moneda)] = [
                'cliente_id' => $linea->cliente_id,
                'cliente_nombre' => $linea->cliente_nombre,
                'tarifa_id' => $linea->tarifa_id,
                'servicio' => $linea->servicio,
                'unidad' => $linea->unidad,
                'moneda' => $linea->moneda,
                'simbolo' => $linea->simbolo,
                'decimales' => $linea->decimales,
                'precio' => Montos::texto($linea->precio),
                'cantidad' => BigDecimal::of($linea->cantidad),
                'dias' => $linea->dias,
                'monto' => BigDecimal::of($linea->monto),
            ];
        }

        $hechos = [];
        $consulta = AporteCierre::query()->where('cierre_id', $cierre->id)->toBase()
            ->select(['id', 'cliente_id', 'tarifa_id', 'moneda', 'fuente', 'origen_id', 'dias', 'cantidad']);
        foreach ($consulta->lazyById(2000, 'id') as $aporte) {
            $hechos[self::clave($aporte->cliente_id, (int) $aporte->tarifa_id, $aporte->moneda)][$aporte->fuente.'|'.$aporte->origen_id]
                = Montos::texto($aporte->cantidad).'|'.$aporte->dias.'|c'.$aporte->id;
        }

        $decisiones = Diferencia::query()->where('cierre_id', $cierre->id)->whereNull('anulada_at')->orderBy('id')->get();
        foreach ($decisiones as $decision) {
            $clave = self::clave($decision->cliente_id, $decision->tarifa_id, $decision->moneda);
            $base = $lineas[$clave] ?? [
                'cliente_id' => $decision->cliente_id,
                'cliente_nombre' => $decision->cliente_nombre,
                'tarifa_id' => $decision->tarifa_id,
                'servicio' => $decision->servicio,
                'unidad' => $decision->unidad,
                'moneda' => $decision->moneda,
                'simbolo' => $decision->simbolo,
                'decimales' => $decision->decimales,
                'precio' => null,
                'cantidad' => BigDecimal::zero(),
                'dias' => 0,
                'monto' => BigDecimal::zero(),
            ];
            $base['cantidad'] = $base['cantidad']->plus($decision->cantidad);
            $base['dias'] += $decision->dias;
            $base['monto'] = $base['monto']->plus($decision->monto);
            // La última decisión deja la línea cobrada con el precio que regía al decidir.
            $base['precio'] = $decision->precio === null ? $base['precio'] : Montos::texto($decision->precio);
            $lineas[$clave] = $base;
        }

        if ($decisiones->isNotEmpty()) {
            $porDecision = $decisiones->mapWithKeys(fn (Diferencia $decision): array => [$decision->id => self::clave($decision->cliente_id, $decision->tarifa_id, $decision->moneda)]);
            $aportes = AporteDiferencia::query()->whereIn('diferencia_id', $porDecision->keys())->orderBy('id')->get();
            foreach ($aportes as $aporte) {
                $clave = $porDecision[$aporte->diferencia_id];
                $hecho = $aporte->fuente.'|'.$aporte->origen_id;
                [$cantidad, $dias, $origen] = isset($hechos[$clave][$hecho]) ? explode('|', $hechos[$clave][$hecho], 3) : ['0', '0', 'd'.$aporte->id];
                $hechos[$clave][$hecho] = Montos::texto(BigDecimal::of($cantidad)->plus($aporte->cantidad)).'|'.((int) $dias + $aporte->dias).'|'.$origen;
            }
        }

        return [$lineas, $hechos];
    }

    /**
     * Referencia y fechas de lo retirado, desde el aporte congelado (c) o la decisión (d) que lo
     * dejó cobrado.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $cambios
     * @param  array<string, array{0: string, 1: string}>  $retirados  origen => [línea, hecho]
     */
    private function completarRetirados(array &$cambios, array $retirados): void
    {
        $ids = ['c' => [], 'd' => []];
        foreach (array_keys($retirados) as $origen) {
            $ids[$origen[0]][] = (int) substr($origen, 1);
        }

        foreach (array_chunk($ids['c'], 1000) as $lote) {
            foreach (AporteCierre::query()->whereIn('id', $lote)->get() as $aporte) {
                [$linea, $hecho] = $retirados['c'.$aporte->id];
                $cambios[$linea][$hecho] = [...$cambios[$linea][$hecho], 'hecho_id' => $aporte->hecho_id, 'referencia' => $aporte->referencia, 'desde' => $aporte->desde->toDateString(), 'hasta' => $aporte->hasta->toDateString()];
            }
        }
        foreach (array_chunk($ids['d'], 1000) as $lote) {
            foreach (AporteDiferencia::query()->whereIn('id', $lote)->get() as $aporte) {
                [$linea, $hecho] = $retirados['d'.$aporte->id];
                $cambios[$linea][$hecho] = [...$cambios[$linea][$hecho], 'hecho_id' => $aporte->hecho_id, 'referencia' => $aporte->referencia, 'desde' => $aporte->desde?->toDateString(), 'hasta' => $aporte->hasta?->toDateString()];
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $antes  lo cobrado en la línea
     * @param  array<string, mixed>|null  $ahora  la línea recalculada
     * @param  array<string, array<string, mixed>>  $hechos
     * @return array<string, mixed>|null
     */
    private function comparar(string $clave, ?array $antes, ?array $ahora, array $hechos): ?array
    {
        $cantidadAntes = $antes['cantidad'] ?? BigDecimal::zero();
        $montoAntes = $antes['monto'] ?? BigDecimal::zero();
        $cantidad = BigDecimal::of($ahora['cantidad'] ?? '0')->minus($cantidadAntes);
        $dias = (int) ($ahora['dias'] ?? 0) - (int) ($antes['dias'] ?? 0);
        $monto = BigDecimal::of($ahora['monto'] ?? '0')->minus($montoAntes);
        if ($cantidad->isZero() && $dias === 0 && $monto->isZero()) {
            return null;
        }

        $base = $ahora ?? $antes;
        $decimales = (int) $base['decimales'];
        $lista = array_values($hechos);
        usort($lista, fn (array $a, array $b): int => [$a['desde'] ?? '', (string) $a['referencia'], $a['origen_id']] <=> [$b['desde'] ?? '', (string) $b['referencia'], $b['origen_id']]);

        return [
            'clave' => $clave,
            'cliente_id' => $base['cliente_id'],
            'cliente_nombre' => $base['cliente_nombre'],
            'tarifa_id' => (int) $base['tarifa_id'],
            'servicio' => $base['servicio'],
            'servicio_nombre' => $this->servicios->nombreServicio($base['servicio']),
            'unidad' => $base['unidad'],
            'unidad_nombre' => $this->servicios->nombreUnidad($base['servicio'], $base['unidad']),
            'moneda' => $base['moneda'],
            'simbolo' => $base['simbolo'],
            'decimales' => $decimales,
            'antes' => $antes === null ? null : [
                'cantidad' => Montos::texto($cantidadAntes),
                'dias' => (int) $antes['dias'],
                'monto' => Montos::enMoneda($montoAntes, $decimales),
                'precio' => $antes['precio'],
            ],
            'ahora' => $ahora === null ? null : [
                'cantidad' => Montos::texto($ahora['cantidad']),
                'dias' => (int) $ahora['dias'],
                'monto' => Montos::enMoneda($ahora['monto'], $decimales),
                'precio' => Montos::texto($ahora['precio']),
            ],
            'diferencia' => [
                'cantidad' => Montos::texto($cantidad),
                'dias' => $dias,
                'monto' => Montos::enMoneda($monto, $decimales),
            ],
            'cambio_precio' => $antes !== null && $ahora !== null && $antes['precio'] !== null
                && ! BigDecimal::of($antes['precio'])->isEqualTo($ahora['precio']),
            'hechos' => $lista,
        ];
    }
}
