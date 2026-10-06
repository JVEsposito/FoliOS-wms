<?php

namespace App\Services\Validacion;

use App\Models\EnvaseValidacion;
use App\Models\Folio;
use Illuminate\Support\Facades\DB;

class ComposicionEtiquetaPt
{
    private array $articulos = [];

    private array $envases = [];

    public function lineas(Folio $folio): array
    {
        $datos = $folio->datos_externos ?? [];
        $lineas = $datos['composicion'] ?? [];
        if ($lineas === []) {
            $lineas = [['cantidad_cajas' => $datos['cantidad_cajas'] ?? 0, 'csg' => $datos['csg'] ?? '', 'fecha_embalaje' => $datos['fecha_embalaje'] ?? null]];
        }

        return array_values(array_map(function ($linea) use ($folio, $datos): array {
            $articuloId = $linea['articulo_validacion_id'] ?? null;
            if (! $articuloId && isset($linea['combinacion_validacion_id'])) {
                $articuloId = DB::table('combinaciones_validacion')->where('id', $linea['combinacion_validacion_id'])->value('articulo_validacion_id');
            }
            if ($articuloId && ! array_key_exists($articuloId, $this->articulos)) {
                $this->articulos[$articuloId] = (array) DB::table('articulos_validacion')->where('id', $articuloId)->where('temporada_id', $folio->temporada_id)->first();
            }
            $articulo = $this->articulos[$articuloId] ?? [];
            $heredados = [];
            foreach ($datos['genealogia_repaletizaje'] ?? [] as $origen) {
                foreach ($origen['composicion_aportada'] ?? [] as $aporte) {
                    if (($aporte['csg'] ?? '') === ($linea['csg'] ?? '') && ($aporte['fecha_embalaje'] ?? null) === ($linea['fecha_embalaje'] ?? null)) {
                        $heredados[] = $origen['especificaciones'] ?? [];
                    }
                }
            }
            foreach (['especie', 'variedad', 'envase', 'cliente'] as $campo) {
                $valores = array_values(array_unique(array_filter(array_column($heredados, $campo))));
                $fallback = count($valores) === 1 ? $valores[0] : (count($valores) > 1 ? 'MIX' : null);
                $linea[$campo] = $linea[$campo] ?? $articulo[$campo] ?? $fallback
                    ?? ($campo === 'variedad' ? $folio->variedad : ($campo === 'cliente' ? $folio->exportadora : ($datos[$campo] ?? '')));
            }
            $linea['envase_validacion_id'] = $linea['envase_validacion_id'] ?? $articulo['envase_validacion_id'] ?? null;
            $linea['articulo_validacion_id'] = $articuloId;

            return $linea;
        }, $lineas));
    }

    public function resumen(Folio $folio, array $lineas): array
    {
        if (! isset($this->envases[$folio->temporada_id])) {
            $this->envases[$folio->temporada_id] = EnvaseValidacion::query()
                ->with(['especie', 'cliente'])->whereHas('especie', fn ($q) => $q->where('temporada_id', $folio->temporada_id))->get();
        }
        $catalogo = $this->envases[$folio->temporada_id];
        $faltantes = [];
        $total = 0;
        $codigos = [];
        $nombres = [];
        foreach ($lineas as $linea) {
            $envase = null;
            if ($linea['envase_validacion_id'] ?? null) {
                $envase = $catalogo->firstWhere('id', $linea['envase_validacion_id']);
            } else {
                $candidatos = $catalogo->filter(fn ($e) => mb_strtoupper($e->nombre) === mb_strtoupper($linea['envase'] ?? '')
                    && mb_strtoupper($e->especie->nombre) === mb_strtoupper($linea['especie'] ?? '')
                    && mb_strtoupper($e->cliente?->nombre ?? '') === mb_strtoupper($linea['cliente'] ?? $folio->exportadora ?? ''));
                if ($candidatos->count() === 1) {
                    $envase = $candidatos->first();
                }
            }
            $nombres[] = $envase?->nombre ?? $linea['envase'];
            $codigos[] = $envase?->codigo_externo ?: ($linea['envase_codigo'] ?? '—');
            if ($envase?->kilos_netos_por_caja === null) {
                $faltantes[] = ['id' => $envase?->id, 'nombre' => $envase?->nombre ?: ($linea['envase'] ?: 'Envase sin identificar'), 'codigo_externo' => $envase?->codigo_externo];
            } else {
                // Enteros en diezmilésimas: evita sumar errores de coma flotante.
                $total += (int) round((float) $envase->kilos_netos_por_caja * 10000) * (int) $linea['cantidad_cajas'];
            }
        }
        $variedades = array_values(array_unique(array_column($lineas, 'variedad')));
        $envases = array_values(array_unique(array_map(fn ($l) => $l['envase_validacion_id'] ?: $l['envase'], $lineas)));
        $mixVariedad = count($variedades) > 1 || in_array('MIX', $variedades, true);
        $mixEnvase = count($envases) > 1 || in_array('MIX', $envases, true);

        return [
            'variedad' => $mixVariedad ? 'MIXTA' : ($variedades[0] ?? ''),
            'envase' => $mixEnvase ? 'MIXTO' : ($nombres[0] ?? ''),
            'envase_codigo' => $mixEnvase ? 'MIXTO' : ($codigos[0] ?? '—'),
            'cantidad_cajas' => array_sum(array_column($lineas, 'cantidad_cajas')),
            'kilos_netos' => $faltantes === [] ? number_format($total / 10000, 2, '.', '') : null,
            'envases_sin_kilos' => array_values(array_unique($faltantes, SORT_REGULAR)),
        ];
    }
}
