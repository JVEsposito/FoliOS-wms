<?php

namespace App\Rrhh\Nomina;

use App\Rrhh\Models\ConceptoNomina;
use App\Rrhh\Models\CondicionLaboral;
use App\Rrhh\Models\PactoConcepto;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CalculadorNovedad
{
    public function __construct(private ReglasChile $reglas) {}

    public function calcular(array $d): array
    {
        $concepto = ConceptoNomina::query()->findOrFail($d['concepto_id']);
        if (! $concepto->activo) {
            $this->error('concepto_id', 'El concepto está inactivo.');
        }
        $pacto = null;
        if (! empty($d['pacto_id'])) {
            $pacto = PactoConcepto::query()->findOrFail($d['pacto_id']);
            if ($pacto->persona_id !== $d['persona_id'] || $pacto->concepto_id !== $concepto->id || $pacto->desde > $d['fecha'] || $pacto->hasta < $d['fecha']) {
                $this->error('pacto_id', 'La asignación no corresponde a la persona, concepto o fecha.');
            }
            $d['cantidad'] = $pacto->cantidad;
            $d['valor'] = $pacto->valor;
        }
        $resultado = ['regla' => ReglasChile::VERSION, 'concepto' => $concepto->toArray(), 'pacto' => $pacto?->toArray(), 'incidencias' => []];
        if ($concepto->calculo !== 'horas_extra') {
            $cantidad = $d['cantidad'] ?? null;
            $valor = $d['valor'] ?? null;
            if ($cantidad === null || $valor === null) {
                $this->error('cantidad', 'Indique cantidad y valor; para porcentaje son tasa y base explícita.');
            }
            if ($concepto->calculo === 'porcentaje' && ReglasChile::decimal($cantidad)->isGreaterThan(100)) {
                $this->error('cantidad', 'El porcentaje no puede superar 100.');
            }
            $total = ReglasChile::decimal($cantidad)->multipliedBy($valor);
            if ($concepto->calculo === 'porcentaje') {
                $total = $total->dividedBy(100, 12, RoundingMode::HalfUp);
            }

            return [...$resultado, 'cantidad' => $cantidad, 'valor' => $valor, 'total' => ReglasChile::pesos($total)];
        }
        $condicion = CondicionLaboral::query()->where('persona_id', $d['persona_id'])->where('desde', '<=', $d['fecha'])->where('hasta', '>=', $d['fecha'])->first();
        if (! $condicion || $condicion->regimen !== 'general') {
            $this->error('persona_id', 'Las horas extra automáticas requieren condiciones vigentes del régimen general con sueldo mensual. Los regímenes especiales requieren revisión específica.');
        }
        $minutos = $d['minutos'] ?? 0;
        $recargo = $d['recargo'] ?? '50';
        if ($minutos < 1) {
            $this->error('minutos', 'Indique los minutos extraordinarios verificados; no se descuentan fracciones de hora.');
        }
        $incidencias = [];
        if ($minutos > 120) {
            $incidencias[] = 'Supera el máximo general de dos horas extraordinarias diarias. Se conserva todo el tiempo para su revisión y pago.';
        }
        if (($d['respaldo_he'] ?? '') === 'pacto') {
            if (empty($d['pacto_desde']) || empty($d['pacto_hasta']) || $d['fecha'] < $d['pacto_desde'] || $d['fecha'] > $d['pacto_hasta'] || $d['pacto_hasta'] > CarbonImmutable::parse($d['pacto_desde'])->addMonthsNoOverflow(3)->toDateString()) {
                $this->error('pacto_hasta', 'El pacto debe cubrir la fecha y no superar tres meses.');
            }
        } elseif (($d['respaldo_he'] ?? '') === 'conocimiento_empleador') {
            $incidencias[] = 'Sin pacto escrito: dejar constancia del conocimiento del empleador. La incidencia no elimina el derecho al pago.';
        } else {
            $this->error('respaldo_he', 'Identifique el pacto o el conocimiento del empleador.');
        }
        $adicionales = PactoConcepto::query()->where('persona_id', $d['persona_id'])->where('desde', '<=', $d['fecha'])->where('hasta', '>=', $d['fecha'])->get()->filter(fn ($p) => $p->concepto['base_he'] === true)->values();
        $base = ReglasChile::decimal($condicion->sueldo_mensual);
        foreach ($adicionales as $p) {
            $base = $base->plus(ReglasChile::decimal($p->cantidad)->multipliedBy($p->valor));
        }
        $minimo = $this->reglas->minimo($d['fecha'], $condicion->tramo_imm, $condicion->horas_semanales);
        if ($base->isLessThan($minimo['monto'])) {
            $incidencias[] = 'Sueldo inferior al ingreso mínimo aplicable: se usa el mínimo como piso del sobresueldo y se requiere revisar diferencias salariales.';
            $base = ReglasChile::decimal($minimo['monto']);
        }

        return [...$resultado, ...$this->reglas->horasExtra(ReglasChile::texto($base), $condicion->horas_semanales, $minutos, $recargo),
            'condicion' => $condicion->toArray(), 'adicionales_sueldo' => $adicionales->toArray(), 'minimo' => $minimo, 'base_he' => ReglasChile::texto($base),
            'minutos' => $minutos, 'recargo' => $recargo, 'incidencias' => $incidencias];
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
