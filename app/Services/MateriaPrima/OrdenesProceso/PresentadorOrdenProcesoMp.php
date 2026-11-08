<?php

namespace App\Services\MateriaPrima\OrdenesProceso;

use App\Models\EntregaFrutaProceso;
use App\Models\LoteVersionOrdenMp;
use App\Models\OrdenProcesoMp;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\VersionOrdenProcesoMp;
use Carbon\CarbonImmutable;

class PresentadorOrdenProcesoMp
{
    /** @return array<string, mixed> */
    public function resumen(OrdenProcesoMp $orden): array
    {
        $reservas = $orden->relationLoaded('reservas') ? $orden->reservas : $orden->reservas()->get();
        $vigente = $orden->versionVigente;
        $lotes = $vigente?->relationLoaded('lotes') ? $vigente->lotes : collect();

        return [
            'id' => $orden->id,
            'codigo' => $orden->codigo,
            'fecha' => $orden->fecha?->toDateString(),
            'turno_inicio' => $orden->turno_inicio?->value,
            'turno_pre_sif' => $orden->turno_inicio?->codigoPreSif(),
            'linea' => $orden->linea?->only(['id', 'codigo', 'nombre']),
            'estado' => $orden->estado->value,
            'tipo_cierre' => $orden->tipo_cierre?->value,
            'numero_version_vigente' => $orden->numero_version_vigente,
            'version' => $orden->version,
            'clientes' => $lotes->pluck('cliente_snapshot')->unique()->values()->all(),
            'progreso' => [
                'lotes' => $reservas->count(),
                'bins_programados' => (int) $reservas->sum('bins_programados'),
                'bins_entregados' => (int) $reservas->sum('bins_entregados'),
                'bins_pendientes' => (int) $reservas->sum('bins_pendientes'),
            ],
            'inicio_estimado' => $lotes->first()?->inicio_estimado?->format('Y-m-d H:i:s'),
            'termino_estimado' => $lotes->last()?->termino_estimado?->format('Y-m-d H:i:s'),
            'cerrado_at' => $orden->cerrado_at?->toIso8601String(),
            'motivo_cancelacion' => $orden->motivo_cancelacion,
        ];
    }

    /** @return array<string, mixed> */
    public function detalle(OrdenProcesoMp $orden): array
    {
        $reservas = $orden->reservas->keyBy('lote_materia_prima_id');
        $real = EntregaFrutaProceso::query()
            ->where('orden_proceso_mp_id', $orden->id)
            ->whereNull('anulado_at')
            ->selectRaw('lote_materia_prima_id, MIN(entregado_at) as primera, MAX(entregado_at) as ultima, COUNT(*) as viajes')
            ->groupBy('lote_materia_prima_id')
            ->get()
            ->keyBy('lote_materia_prima_id');
        $version = $orden->versionVigente ? $this->version($orden->versionVigente, $reservas->all()) : null;
        if ($version) {
            // Los horarios estimados son hora de planta; las entregas se guardan en UTC.
            $zona = (string) config('app.operational_timezone');
            $local = fn (?string $valor): ?CarbonImmutable => $valor
                ? CarbonImmutable::parse($valor, 'UTC')->setTimezone($zona)
                : null;
            $version['lotes'] = array_map(function (array $fila) use ($real, $local, $zona): array {
                $entregas = $real[$fila['lote_materia_prima_id']] ?? null;
                $primera = $local($entregas?->getAttribute('primera'));
                $ultima = $local($entregas?->getAttribute('ultima'));
                $inicio = $fila['inicio_estimado'] ? CarbonImmutable::parse($fila['inicio_estimado'], $zona) : null;

                return [
                    ...$fila,
                    'viajes' => (int) ($entregas?->getAttribute('viajes') ?? 0),
                    'primera_entrega_at' => $primera?->format('Y-m-d H:i:s'),
                    'ultima_entrega_at' => $ultima?->format('Y-m-d H:i:s'),
                    // Positivo: el lote empezó después de lo estimado.
                    'desfase_minutos' => $primera && $inicio ? (int) round($inicio->diffInMinutes($primera, false)) : null,
                ];
            }, $version['lotes']);
        }

        return [
            ...$this->resumen($orden),
            'creado_por' => $orden->creadoPor?->name,
            'cerrado_por' => $orden->cerradoPor?->name,
            'version_vigente' => $version,
            'entregas' => EntregaFrutaProceso::query()->where('orden_proceso_mp_id', $orden->id)
                ->with(['lote:id,numero_lote', 'versionOrdenProceso.lotes', 'entregadoPor:id,name', 'correccionesEmbalaje.usuario:id,name'])
                ->orderByDesc('entregado_at')->get()->map(fn (EntregaFrutaProceso $entrega): array => $this->entrega($entrega))->all(),
            'versiones' => $orden->versiones->map(fn (VersionOrdenProcesoMp $version): array => [
                'id' => $version->id,
                'numero' => $version->numero,
                'origen' => $version->origen->value,
                'motivo_cambio' => $version->motivo_cambio,
                'creada_por' => $version->creadaPor?->name,
                'creada_at' => $version->creada_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function entrega(EntregaFrutaProceso $entrega): array
    {
        return [
            'id' => $entrega->id,
            'numero_lote' => $entrega->lote?->numero_lote,
            'cantidad_envases' => $entrega->cantidad_envases,
            'entregado_at' => $entrega->entregado_at?->toIso8601String(),
            'entregado_por' => $entrega->entregadoPor?->name,
            'anulado' => $entrega->anulado_at !== null,
            'version_embalaje' => (int) $entrega->version_embalaje,
            ...$entrega->embalajeRegistrado(),
            'correcciones_embalaje' => $entrega->correccionesEmbalaje->sortBy('version')->map(fn ($correccion): array => [
                'id' => $correccion->id, 'version' => $correccion->version,
                'anterior' => $correccion->anterior, 'nuevo' => $correccion->nuevo,
                'motivo' => $correccion->motivo, 'usuario' => $correccion->usuario?->name,
                'created_at' => $correccion->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, ReservaLoteOrdenProcesoMp>  $reservas
     * @return array<string, mixed>
     */
    public function version(VersionOrdenProcesoMp $version, array $reservas = []): array
    {
        $version->loadMissing('lotes.lote:id,numero_lote');

        return [
            'id' => $version->id,
            'numero' => $version->numero,
            'origen' => $version->origen->value,
            'hora_inicio' => substr((string) $version->hora_inicio, 0, 5),
            'colacion_desde' => $version->colacion_desde ? substr((string) $version->colacion_desde, 0, 5) : null,
            'colacion_hasta' => $version->colacion_hasta ? substr((string) $version->colacion_hasta, 0, 5) : null,
            'rendimiento_kg_hora' => (float) $version->rendimiento_kg_hora,
            'motivo_cambio' => $version->motivo_cambio,
            'lotes' => $version->lotes->map(function (LoteVersionOrdenMp $fila) use ($reservas): array {
                $reserva = $reservas[$fila->lote_materia_prima_id] ?? null;

                return [
                    'id' => $fila->id,
                    'lote_materia_prima_id' => $fila->lote_materia_prima_id,
                    'secuencia' => $fila->secuencia,
                    'numero_proceso' => $fila->numero_proceso,
                    'numero_lote' => $fila->numero_lote_snapshot,
                    'numero_lote_actual' => $fila->lote?->numero_lote ?? $fila->numero_lote_snapshot,
                    'cliente' => ['id' => $fila->cliente_id, 'nombre' => $fila->cliente_snapshot],
                    'especie' => $fila->especie_snapshot,
                    'variedad' => $fila->variedad_snapshot,
                    'fecha_cosecha' => $fila->fecha_cosecha_snapshot?->toDateString(),
                    'csg' => $fila->csg_snapshot,
                    'productor' => $fila->productor_snapshot,
                    'comuna' => $fila->comuna,
                    'provincia' => $fila->provincia,
                    'bins_programados' => $fila->bins_programados,
                    'totes_programados' => $fila->totes_programados,
                    'kilos_programados' => (float) $fila->kilos_programados,
                    'embalaje' => $fila->embalaje,
                    'kilos_por_caja' => $fila->kilos_por_caja !== null ? (float) $fila->kilos_por_caja : null,
                    'cajas_estimadas' => $fila->cajas_estimadas !== null ? (float) $fila->cajas_estimadas : null,
                    'mercado_destino' => $fila->mercado_destino,
                    'inicio_estimado' => $fila->inicio_estimado?->format('Y-m-d H:i:s'),
                    'termino_estimado' => $fila->termino_estimado?->format('Y-m-d H:i:s'),
                    'bins_entregados' => $reserva?->bins_entregados ?? 0,
                    'bins_pendientes' => $reserva?->bins_pendientes ?? 0,
                ];
            })->values()->all(),
        ];
    }
}
