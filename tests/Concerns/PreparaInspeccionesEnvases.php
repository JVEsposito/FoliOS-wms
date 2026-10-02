<?php

namespace Tests\Concerns;

use App\Models\RecepcionRomana;

trait PreparaInspeccionesEnvases
{
    // Los escenarios anteriores preparan explícitamente la nueva inspección;
    // no altera requests globalmente ni las pruebas negativas de RC-02.
    protected function payloadConInspeccionRc02(string $ruta, array $datos): array
    {
        if ($datos === [] || array_key_exists('inspeccion_envases', $datos)) {
            return $datos;
        }
        $esMp = str_contains($ruta, '/validaciones/');
        if ($esMp) {
            $cantidades = collect($datos['envases'] ?? [])->mapWithKeys(fn ($e) => [$e['tipo_envase'] => $e['cantidad_validada']])->all();
        } else {
            if (($datos['modo_salida_envases'] ?? 'vacio') === 'vacio') {
                return $datos;
            }
            preg_match('~/recepciones/([^/]+)/~', $ruta, $m);
            $recepcion = RecepcionRomana::find($m[1] ?? '');
            if (! $recepcion) {
                return $datos;
            }
            $cantidades = ($datos['modo_salida_envases'] ?? '') === 'mismos' ? $recepcion->detallesEnvases->mapWithKeys(fn ($e) => [$e->tipo_envase->value => $e->cantidad_validada ?? $e->cantidad_declarada])->all()
                : collect($datos['salida_envases'] ?? [])->mapWithKeys(fn ($e) => [$e['tipo_envase'] => $e['cantidad']])->all();
        }
        $datos['inspeccion_envases'] = $this->inspeccionRc02DePrueba($cantidades, $esMp);

        return $datos;
    }

    protected function inspeccionRc02DePrueba(array $cantidades, bool $recepcion = true): array
    {
        return ['items' => collect($cantidades)->filter(fn ($n) => $n > 0)->map(fn ($n, $tipo) => ['tipo_envase' => $tipo, 'limpieza' => true, 'condicion' => 'buena', 'nota' => null])->values()->all(), 'observacion' => 'Inspección de prueba', ...($recepcion ? ['coincide_especie_variedad' => true, 'coincide_cantidad_bins' => true, 'bins_bien_etiquetados' => true] : [])];
    }
}
