<?php

namespace App\Finanzas\Valorizacion;

use App\Finanzas\Prefactura\Montos;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

/** Aritmética exacta sobre versiones capturadas; nunca ejecuta código ni consultas del usuario. */
final class EvaluadorRegla
{
    /** @param iterable<array<string, mixed>> $hechos */
    public function evaluar(array $regla, iterable $hechos): array
    {
        $grupos = [];
        foreach ($hechos as $hecho) {
            foreach ($regla['terminos'] as $indice => $termino) {
                if ($hecho['fuente'] !== $termino['fuente'] || ! $this->coincide($hecho['datos'], $termino['filtros'])) {
                    continue;
                }
                $dimensiones = [];
                foreach ($regla['agrupar'] as $campo) {
                    $valor = in_array($campo, ['origen_id', 'fecha_operacional'], true) ? ($hecho[$campo] ?? null) : ($hecho['datos'][$campo] ?? null);
                    if (! is_scalar($valor) || (string) $valor === '') {
                        throw new DomainException("El hecho {$hecho['id']} no tiene la referencia de agrupación {$campo}.");
                    }
                    $dimensiones[$campo] = (string) $valor;
                }
                // Nunca se compensan cantidades de distintos clientes o temporadas.
                // Un cliente sin vincular conserva además su nombre de origen.
                $clave = hash('sha256', json_encode([$hecho['cliente_id'], $hecho['cliente_id'] === null ? $hecho['cliente_nombre'] : null, $hecho['temporada_id'], $dimensiones], JSON_THROW_ON_ERROR));
                if (! isset($grupos[$clave])) {
                    if (count($grupos) >= 10000) {
                        throw new DomainException('La regla supera 10.000 grupos. Acote sus filtros antes de capturar.');
                    }
                    $grupos[$clave] = ['clave' => $clave, 'ancla' => null, 'dimensiones' => $dimensiones, 'cantidad' => BigDecimal::zero(), 'terminos' => [], 'aportes' => []];
                }
                $grupo = &$grupos[$clave];
                $valor = $hecho['medidas'][$termino['medida']] ?? null;
                if (! is_numeric($valor)) {
                    throw new DomainException("El hecho {$hecho['id']} no contiene la medida {$termino['medida']}.");
                }
                if (count($grupo['aportes']) >= 5000) {
                    throw new DomainException('Un grupo supera 5.000 aportes. Use una agrupación más específica.');
                }
                $cantidad = BigDecimal::of((string) $valor)->multipliedBy($termino['factor']);
                $grupo['cantidad'] = $grupo['cantidad']->plus($cantidad);
                $grupo['terminos'][$indice] = true;
                $grupo['aportes'][] = ['hecho_id' => $hecho['id'], 'fuente' => $hecho['fuente'], 'origen_id' => $hecho['origen_id'], 'version' => $hecho['version'], 'medida' => $termino['medida'], 'valor' => Montos::texto((string) $valor), 'factor' => $termino['factor'], 'cantidad' => Montos::texto($cantidad)];
                // El primer término determina la fecha del servicio. Un retorno posterior
                // corrige ese servicio y el cierre detecta la diferencia, sin mover el cobro.
                if ($indice === 0 && ($grupo['ancla'] === null || [$hecho['ocurrido_at'], $hecho['origen_id']] < [$grupo['ancla']['ocurrido_at'], $grupo['ancla']['origen_id']])) {
                    $grupo['ancla'] = $hecho;
                }
                unset($grupo);
            }
        }
        ksort($grupos);

        return array_values(array_map(function (array $grupo) use ($regla): array {
            $cantidad = $grupo['cantidad']->toScale(6, RoundingMode::HalfUp);
            if (strlen(explode('.', (string) $cantidad->abs())[0]) > 18) {
                throw new DomainException('La cantidad calculada supera los 18 dígitos enteros admitidos.');
            }
            $grupo['cantidad'] = Montos::texto($cantidad);
            $grupo['completo'] = $grupo['ancla'] !== null && (! $regla['exigir_todos'] || count($grupo['terminos']) === count($regla['terminos']));
            unset($grupo['terminos']);

            return $grupo;
        }, $grupos));
    }

    private function coincide(array $datos, array $filtros): bool
    {
        foreach ($filtros as $campo => $valor) {
            if (! is_scalar($datos[$campo] ?? null) || (string) $datos[$campo] !== $valor) {
                return false;
            }
        }

        return true;
    }
}
