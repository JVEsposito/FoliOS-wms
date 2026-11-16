<?php

namespace App\Services\Materiales;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Models\Cliente;
use App\Models\EnvaseValidacion;
use App\Models\OrigenValidacion;
use App\Models\ProduccionRebajaEstandar;
use App\Models\Temporada;
use App\Models\ValidacionPallet;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use Illuminate\Support\Collection;

/**
 * Diferencias entre lo ya rebajado y lo que hoy dice Validación PT para esos mismos pallets:
 * una corrección de cajas, embalaje, marca o fecha, o una anulación posterior a la rebaja.
 *
 * Se compara por pallet y combinación de día, cliente, marca, proceso, embalaje, variedad,
 * calibre y categoría. Lo que hoy tiene más cajas que lo rebajado es un ajuste positivo (falta
 * descontar); lo que tiene menos, un ajuste negativo (se descontó de más y queda a favor).
 */
class DiferenciasRebajaEstandar
{
    public function __construct(private readonly ProduccionValidadaEstandar $produccion) {}

    /** @return list<array<string, mixed>> */
    public function grupos(Temporada $temporada, string $desde, string $hasta, array $filtros = []): array
    {
        // Solo los pallets que tocan el período: rebajados con ese día de proceso o que hoy lo tienen.
        $confirmadas = fn ($consulta) => $consulta->where('estado', 'confirmada')->where('temporada_id', $temporada->id);
        $porRegistro = ProduccionRebajaEstandar::query()->whereHas('rebaja', $confirmadas)
            ->whereBetween('fecha_proceso', [$desde, $hasta])->distinct()->pluck('validacion_pallet_id');
        $porFechaActual = ValidacionPallet::query()
            ->where('temporada_id', $temporada->id)
            ->whereBetween('snapshot->fecha_embalaje', [$desde, $hasta])
            ->whereIn('id', ProduccionRebajaEstandar::query()->select('validacion_pallet_id')->whereHas('rebaja', $confirmadas))
            ->pluck('id');
        $rebajado = ProduccionRebajaEstandar::query()
            ->whereHas('rebaja', $confirmadas)
            ->whereIn('validacion_pallet_id', $porRegistro->merge($porFechaActual)->unique()->values())
            ->get();
        if ($rebajado->isEmpty()) {
            return [];
        }
        $validaciones = ValidacionPallet::query()
            ->whereIn('id', $rebajado->pluck('validacion_pallet_id')->unique())
            ->get(['id', 'numero_folio', 'estado', 'resultado', 'folio_id', 'snapshot'])
            ->keyBy('id');
        $origenes = OrigenValidacion::query()
            ->with(['clienteCatalogo:id,cliente_id', 'marcaCatalogo:id,nombre'])
            ->whereIn('id', $validaciones->flatMap(fn (ValidacionPallet $validacion): array => array_column($validacion->snapshot['composicion'] ?? [], 'origen_validacion_id'))->filter()->unique())
            ->get()->keyBy('id');

        $diferencias = [];
        foreach ($rebajado->groupBy('validacion_pallet_id') as $validacionId => $filas) {
            $validacion = $validaciones->get($validacionId);
            $vigente = $validacion
                && $validacion->estado === EstadoValidacionPallet::Aceptada
                && $validacion->resultado === ResultadoValidacionPallet::Aprobado
                && $validacion->folio_id !== null;
            $actual = $vigente ? $this->actual($validacion, $origenes) : [];
            $antes = [];
            foreach ($filas as $fila) {
                $atributos = $this->atributosRegistro($fila);
                $clave = self::clave($atributos);
                $antes[$clave] ??= ['atributos' => $atributos, 'cajas' => 0];
                $antes[$clave]['cajas'] += $fila->cajas;
            }
            foreach (array_unique([...array_keys($antes), ...array_keys($actual)]) as $clave) {
                $cajas = ($actual[$clave]['cajas'] ?? 0) - ($antes[$clave]['cajas'] ?? 0);
                if ($cajas === 0) {
                    continue;
                }
                $atributos = $actual[$clave]['atributos'] ?? $antes[$clave]['atributos'];
                if (! $this->enFiltros($atributos, $desde, $hasta, $filtros)) {
                    continue;
                }
                $diferencias[$clave] ??= [...$atributos, 'cajas' => 0, 'pallets' => [], 'lineas' => [], 'motivos' => []];
                $diferencias[$clave]['cajas'] += $cajas;
                $diferencias[$clave]['pallets'][$validacionId] = true;
                $diferencias[$clave]['motivos'][$vigente ? 'corregida' : 'anulada'] = true;
                $diferencias[$clave]['lineas'][] = [
                    'validacion_pallet_id' => $validacionId,
                    'numero_folio' => $validacion?->numero_folio ?? $filas->first()->numero_folio,
                    'linea' => $actual[$clave]['linea'] ?? $filas->first()->linea,
                    'cajas' => $cajas,
                ];
            }
        }
        $clientes = Cliente::query()->whereIn('id', collect($diferencias)->pluck('cliente_id')->filter()->unique())->pluck('nombre', 'id');
        $envases = EnvaseValidacion::query()->whereIn('id', collect($diferencias)->pluck('envase_validacion_id')->filter()->unique())->get(['id', 'nombre', 'codigo_externo'])->keyBy('id');

        return collect($diferencias)
            ->reject(fn (array $grupo): bool => $grupo['cajas'] === 0)
            ->map(function (array $grupo, string $clave) use ($temporada, $clientes, $envases): array {
                $envase = $envases->get($grupo['envase_validacion_id'] ?? '');
                $base = [
                    ...$grupo,
                    'clave' => 'ajuste-'.$clave,
                    'tipo' => 'ajuste',
                    'cliente' => $grupo['cliente_id'] ? ['id' => $grupo['cliente_id'], 'nombre' => $clientes->get($grupo['cliente_id'])] : null,
                    'envase' => $envase ? ['id' => $envase->id, 'nombre' => $envase->nombre, 'codigo' => $envase->codigo_externo] : null,
                    'pallets' => count($grupo['pallets']),
                    'motivo' => isset($grupo['motivos']['anulada']) ? (isset($grupo['motivos']['corregida']) ? 'Corregidos o anulados' : 'Anulados') : 'Corregidos',
                ];
                unset($base['motivos']);

                return $this->produccion->conEstandar($temporada, $base);
            })
            ->sortBy(fn (array $grupo): string => $grupo['fecha'].'|'.($grupo['cliente']['nombre'] ?? '~').'|'.($grupo['marca'] ?? '~'))
            ->values()->all();
    }

    /** @return array<string, array{atributos: array<string, ?string>, cajas: int, linea: int}> */
    private function actual(ValidacionPallet $validacion, Collection $origenes): array
    {
        $snapshot = $validacion->snapshot;
        $lineas = [];
        foreach ($snapshot['composicion'] ?? [] as $indice => $linea) {
            if (! is_array($linea) || (int) ($linea['cantidad_cajas'] ?? 0) <= 0) {
                continue;
            }
            $origen = $origenes->get($linea['origen_validacion_id'] ?? '');
            $atributos = [
                'fecha' => (string) ($linea['fecha_embalaje'] ?? $snapshot['fecha_embalaje'] ?? ''),
                'cliente_id' => $origen?->clienteCatalogo?->cliente_id,
                'marca' => ServicioEstandarMaterial::normalizarVariacion($origen?->marcaCatalogo?->nombre ?? $origen?->marca ?? ($snapshot['origen']['marca'] ?? null)),
                'proceso' => ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso_packing'] ?? null),
                'envase_validacion_id' => $linea['envase_validacion_id'] ?? null,
                'variedad' => ServicioEstandarMaterial::normalizarVariacion($linea['variedad'] ?? ($snapshot['articulo']['variedad'] ?? null)),
                'calibre' => ServicioEstandarMaterial::normalizarVariacion($snapshot['articulo']['calibre'] ?? null),
                'categoria' => ServicioEstandarMaterial::normalizarVariacion($snapshot['categoria']['nombre'] ?? null),
            ];
            $clave = self::clave($atributos);
            $lineas[$clave] ??= ['atributos' => $atributos, 'cajas' => 0, 'linea' => $indice];
            $lineas[$clave]['cajas'] += (int) $linea['cantidad_cajas'];
        }

        return $lineas;
    }

    /** @return array<string, ?string> */
    private function atributosRegistro(ProduccionRebajaEstandar $fila): array
    {
        return [
            'fecha' => $fila->fecha_proceso->toDateString(),
            'cliente_id' => $fila->cliente_id,
            'marca' => $fila->marca,
            'proceso' => $fila->proceso,
            'envase_validacion_id' => $fila->envase_validacion_id,
            'variedad' => $fila->variedad,
            'calibre' => $fila->calibre,
            'categoria' => $fila->categoria,
        ];
    }

    /** @param array<string, ?string> $atributos */
    private function enFiltros(array $atributos, string $desde, string $hasta, array $filtros): bool
    {
        return $atributos['fecha'] >= $desde && $atributos['fecha'] <= $hasta
            && (! ($filtros['cliente_id'] ?? null) || $atributos['cliente_id'] === $filtros['cliente_id'])
            && (! ($filtros['marca'] ?? null) || $atributos['marca'] === ServicioEstandarMaterial::normalizarVariacion($filtros['marca']))
            && (! ($filtros['proceso'] ?? null) || $atributos['proceso'] === ProyeccionTrazabilidadFolio::normalizarCodigo($filtros['proceso']));
    }

    /** Misma clave que los grupos de producción: la rebaja usa una sola forma de agrupar. */
    public static function clave(array $atributos): string
    {
        return ProduccionValidadaEstandar::clave($atributos);
    }
}
