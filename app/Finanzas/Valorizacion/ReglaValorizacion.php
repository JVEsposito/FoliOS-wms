<?php

namespace App\Finanzas\Valorizacion;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Prefactura\Montos;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Expresión declarativa: suma de medidas por coeficientes, con unidad y agrupación explícitas. */
final class ReglaValorizacion
{
    public const FUENTE = 'calculado';

    public function __construct(private readonly CatalogoFuentes $fuentes) {}

    public function normalizar(array $regla): array
    {
        $regla = Validator::make($regla, [
            'agrupar' => ['required', 'array', 'min:1', 'max:4'],
            'agrupar.*' => ['required', 'string', 'distinct'],
            'exigir_todos' => ['required', 'boolean'],
            'terminos' => ['required', 'array', 'min:1', 'max:8'],
            'terminos.*' => ['required', 'array:fuente,medida,factor,filtros'],
            'terminos.*.fuente' => ['required', 'string'],
            'terminos.*.medida' => ['required', 'string'],
            'terminos.*.factor' => ['required', 'regex:/^-?\d{1,6}(?:\.\d{1,6})?$/'],
            'terminos.*.filtros' => ['present', 'array', 'max:8'],
            'terminos.*.filtros.*' => ['required', 'string', 'max:180'],
        ])->validate();
        $unidad = null;
        $origenes = [];
        foreach ($regla['terminos'] as &$termino) {
            $fuente = $this->fuentes->todas()[$termino['fuente']] ?? null;
            if ($fuente === null || in_array($termino['fuente'], [self::FUENTE, 'declarado'], true) || $fuente->intervalo()) {
                $this->error('Elija fuentes operativas puntuales. Los intervalos y acuerdos tienen su propia valorización.');
            }
            $medida = $fuente->medidas()[$termino['medida']] ?? null;
            if ($medida === null) {
                $this->error('Los términos deben usar medidas existentes de la misma unidad.');
            }
            $unidadTermino = $medida['unidad'];
            if (isset($medida['dimension_unidad'])) {
                $unidadTermino = $termino['filtros'][$medida['dimension_unidad']] ?? null;
                if (! is_string($unidadTermino) || trim($unidadTermino) === '') {
                    $this->error('Para valorizar cantidades de materiales, filtre una unidad de medida explícita. No se suman cajas, kilos u otras unidades diferentes.');
                }
            }
            if ($unidad !== null && $unidad !== $unidadTermino) {
                $this->error('Los términos deben usar medidas de la misma unidad.');
            }
            $unidad = $unidadTermino;
            $dimensiones = array_keys($this->fuentes->contexto($termino['fuente'])['dimensiones']);
            $agrupaciones = ['origen_id', 'fecha_operacional', ...$dimensiones];
            if (array_diff($regla['agrupar'], $agrupaciones) !== [] || array_diff(array_keys($termino['filtros']), $dimensiones) !== []) {
                $this->error('La agrupación o los filtros no están disponibles en todas las fuentes elegidas.');
            }
            $factor = BigDecimal::of($termino['factor']);
            if ($factor->isZero()) {
                $this->error('El coeficiente de cada término debe ser distinto de cero.');
            }
            $termino['factor'] = Montos::texto($factor);
            ksort($termino['filtros']);
            $origenes[] = $termino['fuente'];
        }
        unset($termino);
        if (in_array('origen_id', $regla['agrupar'], true) && count(array_unique($origenes)) !== 1) {
            $this->error('Para combinar fuentes use una referencia común, como proceso, lote o fecha; sus identificadores no son intercambiables.');
        }

        return ['agrupar' => array_values($regla['agrupar']), 'exigir_todos' => (bool) $regla['exigir_todos'], 'terminos' => array_values($regla['terminos']), 'unidad' => $unidad];
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['regla' => $mensaje]);
    }
}
