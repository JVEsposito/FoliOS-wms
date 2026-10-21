<?php

namespace App\Rrhh\Nomina;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Cálculos explicables sobre bases verificadas. No reconstruye una liquidación completa ni ejecuta fórmulas ingresadas por usuarios. */
final class CalculosLegalesChile
{
    public const VERSION = 'CL-CALCULOS-2026-10-21-v1';

    public function anticipo50(string $base, string $imm): array
    {
        $porcentaje = $this->tasa($base, '25');
        $tope = $this->positivo($imm)->multipliedBy('4.75')->dividedBy(12, 12, RoundingMode::HalfUp);

        return $this->resultado([
            $this->linea('GRAT50_ANTICIPO', 'Anticipo de gratificación artículo 50', 'haber', ReglasChile::pesos(BigDecimal::min($porcentaje, $tope)), $base, '25'),
        ], ['tope_mensual_referencia' => ReglasChile::texto($tope), 'imm' => $imm, 'requiere_cierre_anual' => true]);
    }

    public function anual50(string $remuneraciones, string $immDiciembre, string $meses, array $anticipos): array
    {
        $servicio = $this->positivo($meses);
        if ($servicio->isGreaterThan(12)) {
            $this->error('meses_computables', 'El tiempo computable no puede superar doce meses; documente su proporcionalidad.');
        }
        $tope = $this->positivo($immDiciembre)->multipliedBy('4.75')->multipliedBy($servicio)->dividedBy(12, 12, RoundingMode::HalfUp);
        $gratificacion = ReglasChile::decimal(ReglasChile::pesos(BigDecimal::min($this->tasa($remuneraciones, '25'), $tope)));
        $abonado = ReglasChile::decimal(0);
        $detalle = [];
        foreach ($anticipos as $a) {
            $actualizado = ReglasChile::pesos($this->noNegativo($a['monto'])->multipliedBy($this->positivo($a['factor_reajuste'])));
            $abonado = $abonado->plus($actualizado);
            $detalle[] = [...$a, 'actualizado' => $actualizado];
        }
        $diferencia = $gratificacion->minus($abonado);

        return $this->resultado([
            $this->linea('GRAT50_ANUAL', 'Gratificación anual artículo 50', 'informativo', (string) $gratificacion, $remuneraciones, '25'),
            $this->linea('ANTICIPOS_REAJUSTADOS', 'Anticipos reajustados por mes', 'informativo', (string) $abonado),
            $this->linea('SALDO_GRATIFICACION', 'Diferencia pendiente de gratificación', 'haber', ReglasChile::pesos(BigDecimal::max($diferencia, 0))),
        ], ['tope_anual_proporcional' => ReglasChile::texto($tope), 'anticipos' => $detalle, 'diferencia' => (string) $diferencia,
            'exceso_a_revisar' => ReglasChile::pesos(BigDecimal::max($diferencia->negated(), 0)), 'descuento_automatico_del_exceso' => false]);
    }

    public function reparto47(string $utilidad, string $porcentaje, string $remuneracionPersona, string $remuneracionTotal): array
    {
        $p = $this->noNegativo($porcentaje);
        $total = $this->positivo($remuneracionTotal);
        $persona = $this->noNegativo($remuneracionPersona);
        if ($p->isLessThan(30) || $p->isGreaterThan(100) || $persona->isGreaterThan($total)) {
            $this->error('reparto', 'El reparto legal es al menos 30% y hasta 100%; la remuneración de la persona no puede superar la computable total.');
        }
        $fondo = $this->tasa($utilidad, $porcentaje);
        $monto = $fondo->multipliedBy($persona)->dividedBy($total, 12, RoundingMode::HalfUp);

        return $this->resultado([$this->linea('GRAT47', 'Participación en gratificación artículo 47', 'haber', ReglasChile::pesos($monto))],
            ['fondo_a_distribuir' => ReglasChile::pesos($fondo), 'porcentaje_reparto' => $porcentaje, 'sin_tope_articulo_50' => true]);
    }

    public function impuesto(string $base, string $utm, string $mes): array
    {
        if (substr($mes, 0, 4) !== '2026') {
            $this->error('mes', 'Falta una versión verificada de la escala tributaria para ese año.');
        }
        $b = $this->noNegativo($base);
        $unidad = $this->positivo($utm);
        // Escala mensual general del SII, factor y cantidad a rebajar en UTM; no tasa efectiva máxima.
        $tramos = [['13.5', '0', '0'], ['30', '0.04', '0.54'], ['50', '0.08', '1.74'], ['70', '0.135', '4.49'], ['90', '0.23', '11.14'], ['120', '0.304', '17.8'], ['310', '0.35', '23.32'], [null, '0.4', '38.82']];
        foreach ($tramos as [$hasta, $factor, $rebaja]) {
            if ($hasta !== null && $b->isGreaterThan($unidad->multipliedBy($hasta))) {
                continue;
            }
            $rebajaPesos = $unidad->multipliedBy($rebaja);
            $impuesto = BigDecimal::max($b->multipliedBy($factor)->minus($rebajaPesos), 0);

            return $this->resultado([$this->linea('IUSC', 'Impuesto único mensual', 'descuento', ReglasChile::pesos($impuesto), $base)],
                ['factor' => $factor, 'rebaja' => ReglasChile::texto($rebajaPesos), 'utm' => $utm, 'mes_tributario' => $mes]);
        }
        throw new \LogicException('La escala debe terminar con un tramo sin límite.');
    }

    public function honorarios(string $bruto, string $mes, bool $retieneReceptor): array
    {
        $tasa = ['2026' => '15.25', '2027' => '16', '2028' => '17'][substr($mes, 0, 4)] ?? null;
        if ($tasa === null) {
            $this->error('mes', 'No hay una tasa de honorarios verificada para ese año.');
        }
        $retencion = ReglasChile::pesos($this->tasa($bruto, $tasa));
        $pago = $this->noNegativo($bruto)->minus($retieneReceptor ? $retencion : 0);

        return $this->resultado([
            $this->linea('HONORARIOS_BRUTO', 'Honorarios brutos de la boleta', 'informativo', $bruto),
            $this->linea('HONORARIOS_RETENCION', $retieneReceptor ? 'Retención a declarar por el receptor' : 'PPM a enterar por el emisor', 'informativo', $retencion, $bruto, $tasa),
            $this->linea('HONORARIOS_PAGO', 'Importe a pagar al prestador', 'informativo', ReglasChile::pesos($pago)),
        ], ['retiene_receptor' => $retieneReceptor, 'no_es_nomina_dependiente' => true, 'cotizaciones_anuales_no_calculadas' => true]);
    }

    public function cotizaciones(array $d, array $p): array
    {
        if ($p['mes'] < '2026-08' || $p['mes'] > '2026-12') {
            $this->error('mes', 'Las tasas y topes verificados cubren agosto a diciembre de 2026. Los topes de 2027 requieren una nueva versión normativa.');
        }
        $imponible = $this->noNegativo($d['base_imponible']);
        $uf = $this->positivo($p['uf']);
        $base = BigDecimal::min($imponible, ReglasChile::pesos($uf->multipliedBy('90')));
        $baseAfc = BigDecimal::min($imponible, ReglasChile::pesos($uf->multipliedBy('135.2')));
        $comision = $this->noNegativo($d['comision_afp']);
        $adicional = $this->noNegativo($d['adicional_accidentes']);
        if ($comision->isGreaterThan(5) || $adicional->isGreaterThan(10)) {
            $this->error('tasa', 'Revise la comisión y la tasa adicional de accidentes con sus certificados vigentes.');
        }
        $lineas = [];
        foreach ([['AFP_10', 'Cotización obligatoria AFP', '10'], ['AFP_COMISION', 'Comisión AFP', $d['comision_afp']]] as [$codigo, $nombre, $tasa]) {
            $lineas[] = $this->porcentaje($codigo, $nombre, 'descuento', $base, $tasa, 'afp');
        }
        $saludLegal = ReglasChile::pesos($this->tasa((string) $base, '7'));
        if ($d['tipo_salud'] === 'fonasa') {
            $ccaf = ! empty($d['distribuir_ccaf']) ? ReglasChile::pesos($this->tasa((string) $base, '3.1')) : '0';
            $lineas[] = $this->linea('FONASA', 'Cotización Fonasa', 'descuento', ReglasChile::pesos(ReglasChile::decimal($saludLegal)->minus($ccaf)), (string) $base, ! empty($d['distribuir_ccaf']) ? '3.9' : '7', 'salud');
            if (! empty($d['distribuir_ccaf'])) {
                $lineas[] = $this->linea('SALUD_CCAF', 'Distribución de salud a CCAF', 'descuento', $ccaf, (string) $base, '3.1', 'ccaf');
            }
            $saludTotal = ReglasChile::decimal($saludLegal);
        } elseif ($d['tipo_salud'] === 'isapre') {
            $plan = $this->positivo($d['plan_salud_clp']);
            $adicionalSalud = BigDecimal::max($plan->minus($saludLegal), 0);
            $lineas[] = $this->linea('ISAPRE_7', 'Cotización legal Isapre', 'descuento', $saludLegal, (string) $base, '7', 'salud');
            $lineas[] = $this->linea('ISAPRE_ADICIONAL', 'Diferencia adicional del plan Isapre', 'descuento', ReglasChile::pesos($adicionalSalud), null, null, 'salud');
            $saludTotal = ReglasChile::decimal($saludLegal)->plus(ReglasChile::pesos($adicionalSalud));
        } else {
            $this->error('tipo_salud', 'Seleccione afiliación Fonasa o Isapre.');
        }
        $indefinido = $d['tipo_contrato'] === 'indefinido';
        $cicCompleta = ! empty($d['cic_completa']);
        $lineas[] = $this->porcentaje('AFC_TRABAJADOR', 'Seguro de cesantía del trabajador', 'descuento', $baseAfc, $indefinido && ! $cicCompleta ? '0.6' : '0', 'afc');
        foreach ([['PENSION_AFP_EMPLEADOR', 'Aporte patronal a cuenta AFP', '0.1', 'afp'], ['PENSION_CRP', 'Cotización con rentabilidad protegida', '0.9', 'fapp'], ['PENSION_SSP', 'SIS y compensación por expectativa de vida', '2.5', 'fapp']] as [$codigo, $nombre, $tasa, $destino]) {
            $lineas[] = $this->porcentaje($codigo, $nombre, 'aporte_empleador', $base, $tasa, $destino);
        }
        $lineas[] = $this->porcentaje('AFC_EMPLEADOR', 'Seguro de cesantía del empleador', 'aporte_empleador', $baseAfc, $indefinido ? ($cicCompleta ? '0.8' : '2.4') : '3', 'afc');
        $lineas[] = $this->porcentaje('ACCIDENTES', 'Seguro de accidentes del trabajo', 'aporte_empleador', $base, ReglasChile::texto($adicional->plus('0.9')), 'accidentes');
        $lineas[] = $this->porcentaje('SANNA', 'Seguro SANNA', 'aporte_empleador', $base, '0.03', 'accidentes');
        $maximoSaludTributario = $this->tasa(ReglasChile::pesos($uf->multipliedBy('90')), '7');

        return $this->resultado($lineas, ['base_previsional' => (string) $base, 'base_afc' => (string) $baseAfc, 'salud_legal_total' => $saludLegal,
            'salud_total' => (string) $saludTotal, 'salud_deducible_tributaria_maxima' => ReglasChile::pesos(BigDecimal::min($saludTotal, $maximoSaludTributario)),
            'pension_empleador_tasa_total' => '3.5', 'sis_incluido_en_ssp' => true, 'caso' => 'capitalizacion_general_mes_completo']);
    }

    private function porcentaje(string $codigo, string $nombre, string $tipo, BigDecimal $base, string $tasa, string $destino): array
    {
        return $this->linea($codigo, $nombre, $tipo, ReglasChile::pesos($this->tasa((string) $base, $tasa)), (string) $base, $tasa, $destino);
    }

    private function linea(string $codigo, string $nombre, string $tipo, string $monto, ?string $base = null, ?string $tasa = null, ?string $destino = null): array
    {
        return compact('codigo', 'nombre', 'tipo', 'monto', 'base', 'tasa', 'destino');
    }

    private function resultado(array $lineas, array $detalle): array
    {
        $totales = [];
        foreach ($lineas as $linea) {
            if ($linea['tipo'] === 'informativo') {
                continue;
            }
            $totales[$linea['tipo']] = ReglasChile::pesos(ReglasChile::decimal($totales[$linea['tipo']] ?? 0)->plus($linea['monto']));
        }

        return ['regla' => self::VERSION, 'moneda' => 'CLP', 'lineas' => $lineas, 'totales' => $totales, 'detalle' => $detalle];
    }

    private function tasa(string $base, string $tasa): BigDecimal
    {
        return $this->noNegativo($base)->multipliedBy($this->noNegativo($tasa))->dividedBy(100, 12, RoundingMode::HalfUp);
    }

    private function noNegativo(string $valor): BigDecimal
    {
        $d = ReglasChile::decimal($valor);
        if ($d->isNegative()) {
            $this->error('monto', 'La base y las tasas no pueden ser negativas.');
        }

        return $d;
    }

    private function positivo(string $valor): BigDecimal
    {
        $d = $this->noNegativo($valor);
        if ($d->isZero()) {
            $this->error('monto', 'El parámetro debe ser mayor que cero.');
        }

        return $d;
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
