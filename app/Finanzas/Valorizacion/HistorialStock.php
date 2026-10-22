<?php

namespace App\Finanzas\Valorizacion;

use App\Finanzas\Prefactura\Montos;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;

/** Reconstruye permanencia con saldos del kardex; el saldo actual solo verifica el resultado. */
final class HistorialStock
{
    public function construir(string $creadoAt, string $inicial, string $actual, array $movimientos, string $zona): array
    {
        if (BigDecimal::of($inicial)->isNegative() || BigDecimal::of($actual)->isNegative()) {
            throw new DomainException('El bodegaje requiere existencias físicas no negativas. Revise el folio.');
        }
        if (count($movimientos) > 5000) {
            throw new DomainException('Un folio supera 5.000 movimientos históricos. Revise su kardex antes de valorizar bodegaje.');
        }
        $grupos = [];
        foreach ($movimientos as $movimiento) {
            $at = CarbonImmutable::parse($movimiento['ocurrido_at'], 'UTC')->utc();
            $movimiento['ocurrido_at'] = $at->toIso8601String();
            $movimiento['cantidad_anterior'] = Montos::texto($movimiento['cantidad_anterior']);
            $movimiento['cantidad_resultante'] = Montos::texto($movimiento['cantidad_resultante']);
            if (BigDecimal::of($movimiento['cantidad_anterior'])->isNegative() || BigDecimal::of($movimiento['cantidad_resultante'])->isNegative()) {
                throw new DomainException('El kardex contiene existencia negativa. Revise el folio antes de valorizar bodegaje.');
            }
            $grupos[$at->format('Y-m-d H:i:s.u')][] = $movimiento;
        }
        ksort($grupos);
        $saldo = Montos::texto($inicial);
        if ($grupos !== []) {
            $primero = reset($grupos);
            $saldo = $this->saldoInicial($primero, $saldo);
        }
        $inicio = CarbonImmutable::parse($creadoAt, 'UTC');
        if ($grupos !== []) {
            $inicio = $inicio->min(CarbonImmutable::parse(array_key_first($grupos), 'UTC'));
        }
        $desde = $inicio;
        $tramos = [];
        $picos = [];
        $aportes = [];
        $tuvoStock = BigDecimal::of($saldo)->isPositive();
        $base = $this->medidas($saldo);
        foreach ($grupos as $instante => $grupo) {
            $at = CarbonImmutable::parse($instante, 'UTC');
            if ($at->greaterThan($desde)) {
                $tramos[] = ['desde_at' => $desde->toIso8601String(), 'hasta_at' => $at->toIso8601String(), 'medidas' => $this->medidas($saldo)];
            }
            $dia = $at->setTimezone($zona)->toDateString();
            // Una salida a medianoche pertenece al día que terminó.
            $pico = $at->setTimezone($zona)->isStartOfDay() ? BigDecimal::zero() : BigDecimal::of($saldo);
            foreach ($this->ordenar($grupo, $saldo) as $movimiento) {
                if ($movimiento['cantidad_anterior'] !== $saldo) {
                    throw new DomainException('Los saldos históricos del folio no forman una secuencia conciliable.');
                }
                $saldo = $movimiento['cantidad_resultante'];
                $pico = $pico->isGreaterThan($saldo) ? $pico : BigDecimal::of($saldo);
                $tuvoStock = $tuvoStock || BigDecimal::of($saldo)->isPositive();
                $aportes[] = $movimiento;
            }
            $guardado = $picos[$dia]['unidades'] ?? '0';
            $pico = $pico->isGreaterThan($guardado) ? $pico : BigDecimal::of($guardado);
            $picos[$dia] = $this->medidas(Montos::texto($pico));
            $desde = $at;
        }
        if (! BigDecimal::of($saldo)->isEqualTo($actual)) {
            throw new DomainException('El saldo actual no concilia con el kardex. Se detuvo el bodegaje para conservar sus cantidades históricas.');
        }
        $tramos[] = ['desde_at' => $desde->toIso8601String(), 'hasta_at' => null, 'medidas' => $this->medidas($saldo)];

        return ['inicio' => $inicio, 'fin' => BigDecimal::of($saldo)->isZero() ? $desde : null, 'medidas' => $base, 'tramos' => $tramos, 'picos_diarios' => $picos, 'aportes' => $aportes, 'tuvo_stock' => $tuvoStock];
    }

    private function medidas(string $saldo): array
    {
        return ['folios' => BigDecimal::of($saldo)->isPositive() ? 1 : 0, 'unidades' => $saldo];
    }

    private function saldoInicial(array $grupo, string $inicial): string
    {
        $grados = [];
        foreach ($grupo as $movimiento) {
            $a = 'q'.$movimiento['cantidad_anterior'];
            $b = 'q'.$movimiento['cantidad_resultante'];
            $grados[$a] = ($grados[$a] ?? 0) + 1;
            $grados[$b] = ($grados[$b] ?? 0) - 1;
        }
        $inicio = array_keys(array_filter($grados, fn (int $grado): bool => $grado === 1));
        if (count($inicio) === 1) {
            return substr($inicio[0], 1);
        }

        return isset($grados['q'.$inicial]) ? $inicial : $grupo[0]['cantidad_anterior'];
    }

    /** Camino por saldos para movimientos del mismo instante, sin depender de UUID aleatorios. */
    private function ordenar(array $grupo, string $saldo): array
    {
        $salidas = [];
        foreach ($grupo as $indice => $movimiento) {
            $salidas['q'.$movimiento['cantidad_anterior']][] = $indice;
        }
        $pila = [[$saldo, null]];
        $orden = [];
        while ($pila !== []) {
            [$nodo, $arista] = $pila[array_key_last($pila)];
            if (! empty($salidas['q'.$nodo])) {
                $indice = array_pop($salidas['q'.$nodo]);
                $pila[] = [$grupo[$indice]['cantidad_resultante'], $indice];
            } else {
                array_pop($pila);
                if ($arista !== null) {
                    $orden[] = $grupo[$arista];
                }
            }
        }
        if (count($orden) !== count($grupo)) {
            throw new DomainException('Hay movimientos simultáneos que no concilian entre sí. Revise el kardex del folio.');
        }

        return array_reverse($orden);
    }
}
