<?php

namespace App\Services\Validacion;

use App\Exceptions\ConflictoOperacion;
use App\Models\EnvaseValidacion;
use App\Models\Folio;
use DomainException;

/** Cantidades enteras en diezmilésimas de kg; nunca compensa diferencias con merma implícita. */
class CuadraturaReembalaje
{
    public static function unidades(string|int|float $kilos): int
    {
        $texto = (string) $kilos;
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/D', $texto)) {
            throw new DomainException('Los kilos deben ser positivos o cero, con hasta cuatro decimales.');
        }
        [$entera, $decimal] = array_pad(explode('.', $texto, 2), 2, '');

        return (int) $entera * 10000 + (int) str_pad($decimal, 4, '0');
    }

    public static function kilos(int $unidades): string
    {
        return intdiv($unidades, 10000).'.'.str_pad((string) ($unidades % 10000), 4, '0', STR_PAD_LEFT);
    }

    public function contexto(Folio $folio, array $composicion): array
    {
        $catalogo = EnvaseValidacion::with(['especie', 'cliente'])
            ->whereHas('especie', fn ($q) => $q->where('temporada_id', $folio->temporada_id))->orderBy('id')->get();
        $normal = fn ($v) => mb_strtoupper(trim((string) $v));
        $permitidos = $catalogo->filter(fn ($e) => $normal($e->especie->nombre) === $normal($folio->datos_externos['especie'] ?? '')
            && ($e->cliente_validacion_id === null || $normal($e->cliente?->nombre) === $normal($folio->exportadora)));
        $errores = [];
        $componentes = collect($composicion)->map(function ($linea) use ($catalogo, $permitidos, $normal, &$errores) {
            $envase = isset($linea['envase_validacion_id']) ? $catalogo->firstWhere('id', $linea['envase_validacion_id']) : null;
            if (! $envase) {
                $candidatos = $permitidos->filter(fn ($e) => $normal($e->nombre) === $normal($linea['envase'] ?? ''));
                $envase = $candidatos->count() === 1 ? $candidatos->first() : null;
            }
            $peso = $linea['kilos_netos_por_caja'] ?? $envase?->kilos_netos_por_caja;
            $unidad = $peso !== null ? self::unidades($peso) : 0;
            if ($unidad <= 0) {
                $errores[] = 'Configura los kilos netos por caja de '.($linea['envase'] ?? 'cada formato de origen').' en el catálogo de envases.';
            }

            return [...$linea, 'kilos_netos_por_caja' => self::kilos($unidad),
                'kilos_entrada' => self::kilos($unidad * (int) $linea['cantidad_cajas'])];
        })->values()->all();
        $formatos = $permitidos->filter(fn ($e) => $e->activo && $e->kilos_netos_por_caja !== null && self::unidades($e->kilos_netos_por_caja) > 0)
            ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'codigo' => $e->codigo_externo, 'kilos_netos_por_caja' => $e->kilos_netos_por_caja])->values()->all();
        if ($formatos === []) {
            $errores[] = 'No hay formatos activos con kilos netos por caja para este cliente y especie. Completa el catálogo de envases.';
        }
        $autorizaciones = $folio->autorizacionesSagActivas()->orderBy('id')->pluck('id')->all();
        $huella = hash('sha256', json_encode([$folio->id, $folio->activo, $folio->estado_operacional->value,
            $folio->condicion_termica->value, $folio->datos_externos['cantidad_cajas'] ?? 0, $componentes, $formatos, $folio->exportadora, $folio->datos_externos['repaletizaje_id'] ?? null, $folio->condicion_sag_id, $autorizaciones], JSON_THROW_ON_ERROR));

        return ['huella' => $huella, 'advertencias' => $folio->condicion_sag_id !== null || $autorizaciones ? ['Esta operación retira la condición y autorizaciones SAG actuales. El nuevo formato requiere revisión.'] : [], 'componentes' => $componentes, 'errores' => array_values(array_unique($errores)),
            'kilos_entrada' => self::kilos(array_sum(array_map(fn ($l) => self::unidades($l['kilos_entrada']), $componentes))),
            'formatos' => $formatos];
    }

    public function preparar(Folio $folio, array $composicion, array $payload): array
    {
        $contexto = $this->contexto($folio, $composicion);
        if ($contexto['errores']) {
            throw new DomainException(implode(' ', $contexto['errores']));
        }
        if (! hash_equals($contexto['huella'], $payload['huella_reembalaje'] ?? '')) {
            throw new ConflictoOperacion('El folio o los pesos de sus formatos cambiaron. Vuelve a consultar el origen antes de reembalar.');
        }
        $entradas = collect($contexto['componentes'])->keyBy('clave');
        $formatos = collect($contexto['formatos'])->keyBy('id');
        $salidas = array_fill_keys($entradas->keys()->all(), 0);
        $resultados = collect($payload['resultados'])->map(function ($resultado) use ($entradas, $formatos, &$salidas): array {
            $formato = $formatos->get($resultado['envase_validacion_id'] ?? '');
            if (! $formato) {
                throw new DomainException('Selecciona un formato activo con kilos configurados para el cliente y especie del origen.');
            }
            $unidad = self::unidades($formato['kilos_netos_por_caja']);
            $pedidas = collect($resultado['composicion'] ?? []);
            if ($pedidas->isEmpty() && $entradas->count() === 1) {
                $pedidas = collect([['clave' => $entradas->keys()->first(), 'cantidad_cajas' => $resultado['cantidad_resultante']]]);
            }
            if ($pedidas->isEmpty() || $pedidas->pluck('clave')->duplicates()->isNotEmpty()) {
                throw new DomainException('Desglosa las cajas de cada resultado por componente del origen, sin repetir líneas.');
            }
            $lineas = $pedidas->map(function ($linea) use ($entradas, $formato, $unidad, &$salidas): array {
                $origen = $entradas->get($linea['clave']);
                if (! $origen) {
                    throw new DomainException('Un componente no pertenece al folio original.');
                }
                $cantidad = (int) $linea['cantidad_cajas'];
                if ($cantidad < 1) {
                    throw new DomainException('Las cajas resultantes deben ser enteras y positivas.');
                }
                $salidas[$linea['clave']] += $cantidad * $unidad;
                unset($origen['clave'], $origen['kilos_entrada'], $origen['articulo_validacion_id'], $origen['combinacion_validacion_id']);
                $nueva = [...$origen, 'cantidad_cajas' => $cantidad, 'envase' => $formato['nombre'],
                    'envase_codigo' => $formato['codigo'], 'envase_validacion_id' => $formato['id'],
                    'kilos_netos_por_caja' => self::kilos($unidad)];
                $nueva['clave'] = ServicioRepaletizaje::claveComposicion($nueva);

                return $nueva;
            })->values()->all();
            if (array_sum(array_column($lineas, 'cantidad_cajas')) !== $resultado['cantidad_resultante']) {
                throw new DomainException('La composición de cada salida debe sumar sus cajas resultantes.');
            }

            return [...$resultado, 'composicion' => $lineas, 'envase' => $formato['nombre'], 'kilos_netos_por_caja' => self::kilos($unidad),
                'kilos_salida' => self::kilos($resultado['cantidad_resultante'] * $unidad)];
        })->values();
        $mermas = collect($payload['mermas'] ?? []);
        if ($mermas->pluck('clave')->duplicates()->isNotEmpty() || $mermas->contains(fn ($l) => ! $entradas->has($l['clave']))) {
            throw new DomainException('La merma debe corresponder a componentes del origen, sin repetirlos.');
        }
        $porClave = $mermas->keyBy('clave');
        $balance = $entradas->map(function ($entrada, $clave) use ($salidas, $porClave): array {
            $in = self::unidades($entrada['kilos_entrada']);
            $merma = self::unidades($porClave->get($clave)['kilos'] ?? '0');
            if ($in !== $salidas[$clave] + $merma) {
                throw new DomainException('No cuadran los kilos de CSG '.$entrada['csg'].' · '.($entrada['variedad'] ?? '').'. Entrada '.self::kilos($in).' kg; salida '.self::kilos($salidas[$clave]).' kg; merma '.self::kilos($merma).' kg.');
            }

            return ['clave' => $clave, 'csg' => $entrada['csg'], 'kilos_entrada' => self::kilos($in), 'kilos_salida' => self::kilos($salidas[$clave]), 'kilos_merma' => self::kilos($merma)];
        })->values()->all();
        $totales = [];
        foreach (['entrada', 'salida', 'merma'] as $campo) {
            $totales['kilos_'.$campo] = self::kilos(array_sum(array_map(fn ($l) => self::unidades($l['kilos_'.$campo]), $balance)));
        }
        if (self::unidades($totales['kilos_merma']) > 0 && blank($payload['motivo_merma'] ?? null)) {
            throw new DomainException('Indica el motivo de la merma del reembalaje.');
        }

        return ['resultados' => $resultados, 'balance' => [...$totales, 'componentes' => $balance], 'componentes_entrada' => $contexto['componentes']];
    }
}
