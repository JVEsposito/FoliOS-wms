<?php

namespace App\Rrhh\Nomina;

use App\Rrhh\Models\ParametroLegal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Régimen general. No interpreta jornadas excepcionales, art. 22 bis ni estatutos especiales. */
final class ReglasChile
{
    public const VERSION = 'CL-2026-10-21-v2';

    public static function jornadaMaxima(string $fecha): int
    {
        return $fecha < '2024-04-26' ? 45 : ($fecha < '2026-04-26' ? 44 : ($fecha < '2028-04-26' ? 42 : 40));
    }

    public static function decimal(string|int $valor): BigDecimal
    {
        return BigDecimal::of($valor);
    }

    public static function texto(BigDecimal $valor): string
    {
        return (string) $valor->strippedOfTrailingZeros();
    }

    public static function pesos(BigDecimal $valor): string
    {
        return (string) $valor->toScale(0, RoundingMode::HalfUp);
    }

    public static function parametros(): array
    {
        return [...config('rrhh-legales', []), ...ParametroLegal::query()->orderBy('desde')->get()->toArray()];
    }

    public function minimo(string $fecha, string $tramo, string $horas): array
    {
        $parametro = collect(self::parametros())->first(fn (array $p): bool => $p['desde'] <= $fecha && $p['hasta'] >= $fecha);
        if (! $parametro) {
            throw ValidationException::withMessages(['fecha' => 'Falta el ingreso mínimo vigente. Registre el parámetro y su respaldo antes de calcular.']);
        }
        $minimo = self::decimal($tramo === 'general' ? $parametro['imm_general'] : $parametro['imm_menor_mayor']);
        // La proporcionalidad corresponde a jornada parcial de hasta 30 horas, no a cualquier jornada inferior al máximo.
        if (self::decimal($horas)->isLessThanOrEqualTo(30)) {
            $minimo = $minimo->multipliedBy($horas)->dividedBy(self::jornadaMaxima($fecha), 12, RoundingMode::HalfUp);
        }

        return ['monto' => self::texto($minimo), 'parametro' => $parametro];
    }

    public function horasExtra(string $base, string $horasSemanales, int $minutos, string $recargo): array
    {
        if (self::decimal($recargo)->isLessThan(50)) {
            throw ValidationException::withMessages(['recargo' => 'El recargo mínimo es 50%.']);
        }
        // Sueldo / 30 × 28 / (horas semanales × 4); precisión decimal, sin factor truncado ni redondeos intermedios en pesos.
        $ordinaria = self::decimal($base)->multipliedBy(7)->dividedBy(self::decimal($horasSemanales)->multipliedBy(30), 18, RoundingMode::HalfUp);
        $extra = $ordinaria->multipliedBy(self::decimal($recargo)->plus(100))->dividedBy(100, 18, RoundingMode::HalfUp);

        return ['valor_hora_ordinaria' => self::texto($ordinaria), 'valor_hora_extra' => self::texto($extra),
            'total' => self::pesos($extra->multipliedBy($minutos)->dividedBy(60, 12, RoundingMode::HalfUp))];
    }

    public function limites(array $totales, string $bruta): array
    {
        $b = self::decimal($bruta);
        $incidencias = [];
        foreach (['voluntario' => 15, 'vivienda' => 30, 'cooperativa' => 25] as $grupo => $porcentaje) {
            if (self::decimal($totales[$grupo] ?? 0)->multipliedBy(100)->isGreaterThan($b->multipliedBy($porcentaje))) {
                $incidencias[] = "El grupo {$grupo} supera el {$porcentaje}% de la remuneración bruta.";
            }
        }
        // DT ORD 3456/37: cooperativa tiene su propio tope del 25%; el conjunto facultativo se limita al 45%.
        $suma = self::decimal(0);
        foreach ($totales as $total) {
            $suma = $suma->plus($total);
        }
        if ($suma->multipliedBy(100)->isGreaterThan($b->multipliedBy(45))) {
            $incidencias[] = 'Los descuentos facultativos superan juntos el 45%.';
        }

        return $incidencias;
    }

    public function topeCcaf(string $fecha, string $liquido, bool $excepcion): int
    {
        $p = collect(self::parametros())->first(fn (array $p): bool => $p['desde'] <= $fecha && $p['hasta'] >= $fecha);
        if (! $p || empty($p['imm_no_remuneracional']) || ! self::decimal($liquido)->isGreaterThan(0)) {
            throw ValidationException::withMessages(['liquido_legal' => 'CCAF requiere base líquida positiva documentada y ambos ingresos mínimos vigentes.']);
        }
        $base = self::decimal($liquido);

        return $base->isLessThanOrEqualTo($p['imm_no_remuneracional']) ? 15 : ($base->isLessThan($p['imm_general']) ? 20 : ($excepcion && $fecha >= '2026-10-02' ? 35 : 25));
    }
}
