<?php

namespace App\Services\Existencias;

use Illuminate\Support\Facades\DB;

/** Proyecta cajas y atributos de cada composición, sin modificar el folio físico. */
class DesgloseProductoTerminado
{
    private const CAMPOS = ['cliente', 'marca', 'especie', 'variedad', 'calibre', 'envase', 'categoria', 'csg', 'predio'];

    private array $combinaciones = [];

    private array $articulos = [];

    private array $origenes = [];

    /** Resuelve catálogos en bloque; nunca una consulta por folio o línea. */
    public function preparar(iterable $folios): void
    {
        $lineas = collect($folios)->flatMap(fn ($folio) => $folio->datos_externos['composicion'] ?? [])->filter(fn ($linea) => is_array($linea));
        $ids = $lineas->pluck('combinacion_validacion_id')->filter()->unique()->all();
        $this->combinaciones = $ids === [] ? [] : DB::table('combinaciones_validacion as combinacion')
            ->whereIn('combinacion.id', $ids)->get(['combinacion.id', 'combinacion.articulo_validacion_id', 'combinacion.origen_validacion_id'])
            ->map(fn ($fila) => (array) $fila)->keyBy('id')->all();
        $ids = $lineas->pluck('articulo_validacion_id')->merge(array_column($this->combinaciones, 'articulo_validacion_id'))->filter()->unique()->all();
        $this->articulos = $ids === [] ? [] : DB::table('articulos_validacion')->whereIn('id', $ids)
            ->get(['id', 'especie', 'variedad', 'calibre', 'envase'])->map(fn ($fila) => (array) $fila)->keyBy('id')->all();
        $ids = $lineas->pluck('origen_validacion_id')->merge(array_column($this->combinaciones, 'origen_validacion_id'))->filter()->unique()->all();
        $this->origenes = $ids === [] ? [] : DB::table('origenes_validacion')->whereIn('id', $ids)
            ->get(['id', 'cliente', 'marca', 'csg', 'predio'])->map(fn ($fila) => (array) $fila)->keyBy('id')->all();
    }

    public function filas(array $cabecera, array $datos): array
    {
        $composicion = $datos['composicion'] ?? [];
        $invalida = ! is_array($composicion);
        $lineas = [];
        foreach (is_array($composicion) ? $composicion : [] as $linea) {
            $cantidad = is_array($linea) ? ($linea['cantidad_cajas'] ?? null) : null;
            if (! is_numeric($cantidad) || (float) $cantidad < 0 || (float) $cantidad !== (float) (int) $cantidad) {
                $invalida = true;
            } elseif ((int) $cantidad > 0) {
                $lineas[] = [...$linea, 'cantidad_cajas' => (int) $cantidad];
            }
        }
        $extra = ['fecha_embalaje' => $datos['fecha_embalaje'] ?? null, 'lote_materia_prima' => null, 'proceso_packing' => null];
        if ($invalida) {
            return [[...$cabecera, ...$extra, 'estado_desglose' => 'Composición inválida: revisar las cantidades registradas']];
        }
        if ($lineas === []) {
            return [[...$cabecera, ...$extra, 'estado_desglose' => $this->hayMix($cabecera) ? 'MIX sin composición registrada' : 'Sin composición registrada']];
        }
        $suma = array_sum(array_column($lineas, 'cantidad_cajas'));
        $total = $cabecera['cantidad_cajas'] ?? null;
        if ($total !== null && $suma > $total) {
            return [[...$cabecera, ...$extra, 'estado_desglose' => 'Composición inconsistente: '.$suma.' cajas para un folio de '.$total]];
        }
        $lineas = $this->recuperarAportesCompletos($lineas, $cabecera, $datos['genealogia_repaletizaje'] ?? []);
        $filas = [];
        foreach ($lineas as $linea) {
            $combinacion = $this->combinaciones[$linea['combinacion_validacion_id'] ?? ''] ?? [];
            $articulo = $this->articulos[$linea['articulo_validacion_id'] ?? $combinacion['articulo_validacion_id'] ?? ''] ?? [];
            $origen = $this->origenes[$linea['origen_validacion_id'] ?? $combinacion['origen_validacion_id'] ?? ''] ?? [];
            $herencia = $this->herencia([...$origen, ...$linea], $datos['genealogia_repaletizaje'] ?? []);
            $fila = [...$cabecera, ...$extra, 'cantidad_cajas' => (int) $linea['cantidad_cajas']];
            foreach (self::CAMPOS as $campo) {
                if (array_key_exists($campo, $herencia) && $herencia[$campo] === null
                    && mb_strtoupper(trim((string) ($cabecera[$campo] ?? ''))) === 'MIX'
                    && $this->valor($linea[$campo] ?? null) === null) {
                    $fila[$campo] = 'MIX';

                    continue;
                }
                $fila[$campo] = $this->valor($linea[$campo] ?? null)
                    ?? $this->valor($herencia[$campo] ?? null)
                    ?? $this->valor($articulo[$campo] ?? null)
                    ?? $this->valor($origen[$campo] ?? null)
                    ?? ($cabecera[$campo] ?? null);
            }
            foreach (array_keys($extra) as $campo) {
                $fila[$campo] = $linea[$campo] ?? $extra[$campo];
            }
            $fila['estado_desglose'] = $this->hayMix($fila) ? 'Desglose parcial: atributo MIX sin detalle' : 'Desglosado';
            $filas[] = $fila;
        }
        // Conservar las cajas que no tienen composición, sin distribuirlas por suposición.
        if ($total !== null && $suma < $total) {
            $filas[] = [...$cabecera, ...$extra, 'cantidad_cajas' => (int) ($total - $suma), 'estado_desglose' => 'Cajas sin composición registrada'];
        }

        return $filas;
    }

    /** Deshacer una agrupación histórica solo si sus aportes cuadran exactamente con toda la composición actual. */
    private function recuperarAportesCompletos(array $lineas, array $cabecera, array $genealogia): array
    {
        $requiere = false;
        foreach (self::CAMPOS as $campo) {
            if (mb_strtoupper(trim((string) ($cabecera[$campo] ?? ''))) === 'MIX') {
                foreach ($lineas as $linea) {
                    $requiere = $requiere || $this->valor($linea[$campo] ?? null) === null;
                }
            }
        }
        if (! $requiere || ($cabecera['cantidad_cajas'] ?? null) !== array_sum(array_column($lineas, 'cantidad_cajas'))) {
            return $lineas;
        }
        $resultado = [];
        $sumas = array_fill(0, count($lineas), 0);
        foreach ($genealogia as $origen) {
            foreach ($origen['composicion_aportada'] ?? [] as $aporte) {
                $cantidad = $aporte['cantidad_cajas'] ?? null;
                if (! is_numeric($cantidad) || (int) $cantidad <= 0 || (float) $cantidad !== (float) (int) $cantidad) {
                    return $lineas;
                }
                $datos = [...($origen['especificaciones'] ?? []), ...$aporte];
                $coincidentes = array_keys(array_filter($lineas, fn ($linea) => $this->coincide($linea, $datos)));
                if (count($coincidentes) !== 1) {
                    return $lineas;
                }
                $indice = $coincidentes[0];
                $sumas[$indice] += (int) $cantidad;
                $resultado[] = [...$lineas[$indice], ...array_filter($datos, fn ($v) => $this->valor($v) !== null), 'cantidad_cajas' => (int) $cantidad];
            }
        }
        foreach ($lineas as $i => $linea) {
            if ($sumas[$i] !== $linea['cantidad_cajas']) {
                return $lineas;
            }
        }

        return $resultado;
    }

    private function herencia(array $linea, array $genealogia): array
    {
        $candidatos = [];
        foreach ($genealogia as $origen) {
            foreach ($origen['composicion_aportada'] ?? [] as $aporte) {
                $datos = [...($origen['especificaciones'] ?? []), ...$aporte];
                if ($this->coincide($linea, $datos)) {
                    $candidatos[] = $datos;
                }
            }
        }
        $resultado = [];
        foreach (self::CAMPOS as $campo) {
            $valores = array_map(fn ($datos) => $this->valor($datos[$campo] ?? null), $candidatos);
            if (in_array(null, $valores, true)) {
                if (count($candidatos) > 1) {
                    $resultado[$campo] = null;
                }

                continue;
            }
            $valores = array_values(array_unique($valores));
            if (count($valores) === 1) {
                $resultado[$campo] = $valores[0];
            } elseif (count($valores) > 1) {
                $resultado[$campo] = null;
            }
        }

        return $resultado;
    }

    private function coincide(array $linea, array $aporte): bool
    {
        if (filled($linea['clave'] ?? null) && filled($aporte['clave'] ?? null)) {
            if ($linea['clave'] !== $aporte['clave']) {
                return false;
            }
            foreach ([...self::CAMPOS, 'fecha_embalaje', 'lote_materia_prima', 'proceso_packing'] as $campo) {
                $a = $this->valor($linea[$campo] ?? null);
                $b = $this->valor($aporte[$campo] ?? null);
                if ($a !== null && $b !== null && mb_strtoupper($a) !== mb_strtoupper($b)) {
                    return false;
                }
            }

            return true;
        }
        // No atribuir especificaciones de otro origen que solo comparte la fecha.
        $csg = $this->valor($linea['csg'] ?? null);
        if ($csg === null || mb_strtoupper($csg) !== mb_strtoupper((string) ($aporte['csg'] ?? ''))) {
            return false;
        }
        foreach (['origen_validacion_id', 'articulo_validacion_id', 'predio', 'fecha_embalaje', 'lote_materia_prima', 'proceso_packing', 'variedad', 'envase', 'calibre'] as $campo) {
            $a = $this->valor($linea[$campo] ?? null);
            $b = $this->valor($aporte[$campo] ?? null);
            if ($a !== null && $b !== null && mb_strtoupper($a) !== mb_strtoupper($b)) {
                return false;
            }
        }

        return true;
    }

    private function valor(mixed $valor): ?string
    {
        if (! is_scalar($valor) || trim((string) $valor) === '' || mb_strtoupper(trim((string) $valor)) === 'MIX') {
            return null;
        }

        return (string) $valor;
    }

    private function hayMix(array $fila): bool
    {
        foreach (self::CAMPOS as $campo) {
            if (mb_strtoupper(trim((string) ($fila[$campo] ?? ''))) === 'MIX') {
                return true;
            }
        }

        return false;
    }
}
