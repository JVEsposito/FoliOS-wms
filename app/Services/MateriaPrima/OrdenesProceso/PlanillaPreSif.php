<?php

namespace App\Services\MateriaPrima\OrdenesProceso;

use App\Models\LoteVersionOrdenMp;
use App\Models\OrdenProcesoMp;
use App\Models\VersionOrdenProcesoMp;
use Carbon\CarbonImmutable;

/**
 * Datos de la PRE-SIF de inspección fitosanitaria en línea, con las columnas de la planilla
 * que hoy se llena en Excel. Sale de una versión publicada: lo exportado no cambia aunque
 * después se corrija un maestro.
 */
class PlanillaPreSif
{
    public const COLUMNAS = [
        'EXPORTADORA', 'N° DE PROCESO', 'N° LOTE', 'ESPECIE', 'VARIEDAD AGRONÓMICA', 'VARIEDAD COMERCIAL',
        'FECHA COSECHA', 'CSG', 'PROVINCIA', 'COMUNA', 'NOMBRE PRODUCTOR', 'BINS', 'TOTES', 'KILOS NETOS',
        'TIEMPO ESTIMADO', 'TIPO DE EMBALAJE', 'N° CAJAS', 'N° CAJAS MUESTRAS', 'HORARIO TÉRMINO LOTE', 'MERCADO DESTINO',
    ];

    public const ANCHOS = [14, 9, 8, 8, 15, 15, 11, 9, 13, 13, 22, 6, 6, 9, 8, 20, 8, 8, 9, 17];

    /**
     * @return array{
     *     encabezado: array<string, string>,
     *     filas: array<int, array<int, string|int|float|null>>,
     *     colacion: array{despues_de: int, texto: string}|null,
     *     totales: array{bins: int, totes: int, kilos: float},
     *     nombre_archivo: string
     * }
     */
    public function construir(OrdenProcesoMp $orden, ?VersionOrdenProcesoMp $version = null): array
    {
        $orden->loadMissing('linea');
        $version ??= $orden->versionVigente()->with('lotes')->firstOrFail();
        $version->loadMissing('lotes');
        $rendimiento = (float) $version->rendimiento_kg_hora;
        $filas = $version->lotes->map(fn (LoteVersionOrdenMp $fila): array => [
            $fila->cliente_snapshot,
            $fila->numero_proceso,
            $fila->numero_lote_snapshot,
            mb_strtoupper($fila->especie_snapshot),
            mb_strtoupper($fila->variedad_snapshot),
            mb_strtoupper($fila->variedad_snapshot),
            $fila->fecha_cosecha_snapshot?->format('d-m-Y'),
            $fila->csg_snapshot,
            $fila->provincia,
            $fila->comuna,
            $fila->productor_snapshot,
            $fila->bins_programados,
            $fila->totes_programados,
            (float) $fila->kilos_programados,
            $this->duracion((float) $fila->kilos_programados, $rendimiento),
            $fila->embalaje,
            $fila->cajas_estimadas !== null ? (float) $fila->cajas_estimadas : null,
            null,
            $fila->termino_estimado?->format('H:i'),
            $fila->mercado_destino,
        ])->values()->all();

        $turno = $orden->turno_inicio;
        $fecha = $orden->fecha->format('d-m-Y');
        $linea = $orden->linea?->codigo ?? '';

        return [
            'encabezado' => [
                'titulo' => 'PRE-SIF DE INSPECCIÓN FITOSANITARIA PARA INSPECCIÓN EN LÍNEA',
                'orden' => "{$orden->codigo} · v{$version->numero}",
                'fecha' => $fecha,
                'version' => 'V'.$version->numero,
                'turno' => $turno->codigoPreSif(),
                'linea' => $linea,
                'csp' => (string) (config('materia_prima.csp_planta') ?? ''),
                'horario_inicio' => substr((string) $version->hora_inicio, 0, 5),
                'vuelta_colacion' => $version->colacion_hasta ? substr((string) $version->colacion_hasta, 0, 5) : '',
            ],
            'filas' => $filas,
            'colacion' => $this->colacion($version),
            'totales' => [
                'bins' => (int) $version->lotes->sum('bins_programados'),
                'totes' => (int) $version->lotes->sum('totes_programados'),
                'kilos' => round((float) $version->lotes->sum('kilos_programados'), 3),
            ],
            'nombre_archivo' => sprintf(
                'pre-sif_%s_v%d_%s_%s_%s',
                strtolower($orden->codigo),
                $version->numero,
                strtolower($turno->codigoPreSif()),
                strtolower($linea),
                $orden->fecha->format('d-m-y'),
            ),
        ];
    }

    /** Tiempo de proceso del lote, sin colación, como la columna de la planilla (kilos ÷ rendimiento). */
    private function duracion(float $kilos, float $rendimiento): ?string
    {
        if ($rendimiento <= 0) {
            return null;
        }
        // Como el formato h:mm de la planilla: los segundos no suman un minuto.
        $minutos = (int) floor($kilos / $rendimiento * 60);

        return sprintf('%d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /**
     * Como en la planilla, la colación va como fila separadora entre lotes: después del último
     * lote que termina antes de salir a colación.
     *
     * @return array{despues_de: int, texto: string}|null
     */
    private function colacion(VersionOrdenProcesoMp $version): ?array
    {
        if (! $version->colacion_desde || ! $version->colacion_hasta) {
            return null;
        }
        $desde = substr((string) $version->colacion_desde, 0, 5);
        $hasta = substr((string) $version->colacion_hasta, 0, 5);
        $despuesDe = 0;
        $primera = $version->lotes->first()?->inicio_estimado;
        foreach ($version->lotes->values() as $indice => $fila) {
            if (! $primera || ! $fila->termino_estimado) {
                continue;
            }
            $salida = CarbonImmutable::parse($primera->format('Y-m-d').' '.$desde);
            if ($salida->lessThan($primera)) {
                $salida = $salida->addDay();
            }
            if ($fila->termino_estimado->lessThanOrEqualTo($salida)) {
                $despuesDe = $indice + 1;
            }
        }

        return ['despues_de' => $despuesDe, 'texto' => "SALIDA A COLACIÓN {$desde} · VUELTA {$hasta}"];
    }
}
