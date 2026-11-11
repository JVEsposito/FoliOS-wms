<?php

namespace App\Services\Operacion;

use App\Enums\EstadoCarga;
use App\Enums\EstadoIncidenciaCarga;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Temporada;
use App\Services\Cargas\CalculadorConcentracionCarga;
use App\Services\Cargas\ServicioReingresoCamara;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Estado de cada carga publicada para «Operación ahora»: cuánto está acopiado
 * junto (separación) y cuánto ya subió al camión (carguío). La separación usa la
 * misma concentración que el planificador y la tablet, para que las tres vistas
 * muestren el mismo número.
 */
class ResumenDespachoOperacion
{
    public function __construct(
        private readonly CalculadorConcentracionCarga $concentracion,
        private readonly ServicioReingresoCamara $reingresos,
    ) {}

    /** @return array<string, mixed> */
    public function obtener(Temporada $temporada): array
    {
        $estados = [...EstadoCarga::visiblesEnOperacion(), EstadoCarga::Despachada];
        $cargas = Carga::query()
            ->where('temporada_id', $temporada->id)
            ->whereIn('estado', array_map(fn (EstadoCarga $estado): string => $estado->value, $estados))
            ->with([
                'andenPrevisto:id,codigo,nombre',
                'camaraObjetivo:id,codigo,nombre',
                'presenciaAndenActiva.anden:id,codigo,nombre',
                'embarque:id,carga_id,cliente_id,codigo',
                'embarque.cliente:id,nombre',
                'embarque.instructivos' => fn ($consulta) => $consulta->orderBy('orden')->orderBy('id'),
                'incidencias' => fn ($consulta) => $consulta
                    ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value)
                    ->with('asignacion:id,carga_id,instructivo_embarque_id'),
                'asignacionesActuales.folio:id,numero_folio,exportadora',
                'asignacionesActuales.folio.ubicacionActual.posicion.camara:id,codigo,nombre,cantidad_bandas,posiciones_por_banda,cantidad_niveles',
            ])
            ->withCount(['incidencias as incidencias_abiertas' => fn (Builder $consulta): Builder => $consulta
                ->where('incidencias_carga_folio.estado', EstadoIncidenciaCarga::Abierta->value)])
            ->orderByRaw("CASE prioridad WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END")
            ->orderBy('publicada_at')
            ->get();
        $exigeCargados = (bool) config('despacho.cierre_exige_pallets_cargados');

        $filas = $cargas->map(function (Carga $carga) use ($exigeCargados): array {
            $asignaciones = $carga->asignacionesActuales;
            $progreso = $this->concentracion->calcular($asignaciones);
            $total = (int) $progreso['total'];
            $presencia = $carga->presenciaAndenActiva;
            $porCargarEnAnden = max(0, (int) $progreso['en_anden'] - (int) $progreso['cargados']);
            $clientes = $carga->embarque?->cliente
                ? [$carga->embarque->cliente->nombre]
                : $asignaciones->map(fn (CargaFolio $asignacion): ?string => $asignacion->folio?->exportadora)
                    ->filter()->unique()->sort()->values()->all();

            return [
                'id' => $carga->id,
                'codigo' => $carga->codigo,
                'numero_orden_externa' => $carga->numero_orden_externa,
                'embarque' => $carga->embarque?->codigo,
                'clientes' => $clientes,
                'estado' => $carga->estado->value,
                'prioridad' => $carga->prioridad->value,
                'camara_objetivo' => $carga->camaraObjetivo?->codigo,
                'anden' => $presencia?->anden?->codigo ?? $carga->andenPrevisto?->codigo,
                'camion_en_anden' => $presencia ? [
                    'patente' => $presencia->patente,
                    'ingresada_at' => $presencia->ingresada_at?->toAtomString(),
                ] : null,
                'incidencias_abiertas' => (int) $carga->incidencias_abiertas,
                'separacion' => [
                    'acopiados' => (int) $progreso['concentrados'],
                    'total' => $total,
                    'porcentaje' => (int) $progreso['porcentaje'],
                    'umbral_porcentaje' => (int) $progreso['umbral_porcentaje'],
                    'cumple_umbral' => (bool) $progreso['cumple_umbral'],
                    'en_anden' => (int) $progreso['en_anden'],
                ],
                'carguio' => [
                    'cargados' => (int) $progreso['cargados'],
                    'total' => $total,
                    'porcentaje' => $total === 0 ? 0 : (int) floor(((int) $progreso['cargados'] / $total) * 100),
                    'en_anden_por_cargar' => $porCargarEnAnden,
                ],
                'lista_para_cerrar' => $carga->estado === EstadoCarga::Despachada
                    && (int) $carga->incidencias_abiertas === 0
                    && (! $exigeCargados || $porCargarEnAnden === 0),
                'publicada_at' => $carga->publicada_at?->toAtomString(),
                'instructivos' => $this->instructivos($carga, $progreso),
            ];
        });

        $reingresos = $this->reingresos->bandeja($temporada->id, incluirUbicados: true, limite: 50);
        $pendientes = array_values(array_filter($reingresos, fn (array $fila): bool => $fila['estado'] === 'pendiente'));

        return [
            'cierre_exige_pallets_cargados' => $exigeCargados,
            'reingresos' => $reingresos,
            'resumen' => [
                'activas' => $filas->count(),
                'en_separacion' => $filas->filter(fn (array $fila): bool => $fila['separacion']['en_anden'] < $fila['separacion']['total'])->count(),
                'cargando' => $filas->filter(fn (array $fila): bool => $fila['camion_en_anden'] !== null || $fila['carguio']['cargados'] > 0)->count(),
                'listas_para_cerrar' => $filas->where('lista_para_cerrar', true)->count(),
                'pallets_por_cargar_en_anden' => $filas->sum(fn (array $fila): int => $fila['carguio']['en_anden_por_cargar']),
                'pendientes_reingreso' => count($pendientes),
                'reingreso_mas_antiguo_minutos' => $pendientes === [] ? null : max(array_column($pendientes, 'antiguedad_minutos')),
            ],
            'cargas' => $filas->values()->all(),
        ];
    }

    /** Subestados informativos: no modifican el estado ni el cierre de la carga. */
    private function instructivos(Carga $carga, array $progreso): array
    {
        $instructivos = $carga->embarque?->instructivos ?? collect();
        if ($instructivos->isEmpty()) {
            return [];
        }
        $ids = $instructivos->pluck('id');
        $asignaciones = $carga->asignacionesActuales;
        $incidencias = $carga->incidencias;
        $filas = $instructivos->map(function ($instructivo) use ($asignaciones, $incidencias, $progreso): array {
            $grupo = $asignaciones->where('instructivo_embarque_id', $instructivo->id)->values();

            return [
                'id' => $instructivo->id,
                'orden' => $instructivo->orden,
                'numero_externo' => $instructivo->numero_externo,
                'booking' => $instructivo->booking,
                'recibidor' => $instructivo->recibidor,
                ...$this->subestado($grupo, $progreso['segregaciones'][$instructivo->id] ?? null,
                    $instructivo->cantidad_pallets,
                    $incidencias->filter(fn ($incidencia): bool => $incidencia->asignacion?->instructivo_embarque_id === $instructivo->id)->count()),
            ];
        });
        $sinInstructivo = $asignaciones->reject(fn ($asignacion): bool => $ids->contains($asignacion->instructivo_embarque_id))->values();
        $incidenciasSinInstructivo = $incidencias->reject(fn ($incidencia): bool => $ids->contains($incidencia->asignacion?->instructivo_embarque_id))->count();
        if ($sinInstructivo->isNotEmpty() || $incidenciasSinInstructivo > 0) {
            $filas->push([
                'id' => null, 'orden' => null, 'numero_externo' => null, 'booking' => null, 'recibidor' => null,
                ...$this->subestado($sinInstructivo, $progreso['segregaciones'][''] ?? null, null, $incidenciasSinInstructivo),
            ]);
        }

        return $filas->values()->all();
    }

    private function subestado(Collection $asignaciones, ?array $progreso, ?int $programados, int $incidencias): array
    {
        // Reutilizar el cálculo global conserva el tratamiento de bandas mezcladas.
        $progreso ??= $this->concentracion->calcular($asignaciones);
        $asignados = $asignaciones->count();
        $total = max($asignados, $programados ?? 0);
        $acopiados = (int) $progreso['concentrados'];
        $cargados = (int) $progreso['cargados'];
        $enAnden = (int) $progreso['en_anden'];
        $porcentaje = $total > 0 ? (int) round($acopiados / $total * 100) : 0;
        $estado = match (true) {
            $incidencias > 0 => 'con_incidencia',
            $asignados === 0 => 'sin_asignar',
            $cargados >= $total => 'cargado_completo',
            $cargados > 0 => 'cargando',
            $enAnden >= $total => 'en_anden',
            $enAnden > 0 => 'despacho_parcial',
            $acopiados >= $total => 'separacion_completa',
            $acopiados > 0 => 'en_preparacion',
            default => 'pendiente',
        };

        return [
            'pallets_programados' => $programados,
            'pallets_asignados' => $asignados,
            'pallets_pendientes_asignar' => $programados === null ? null : max(0, $programados - $asignados),
            'pendientes_ubicacion' => $asignaciones->filter(fn ($a): bool => ! $a->estado->fueraDeCamara()
                && $a->folio?->ubicacionActual?->posicion === null)->count(),
            'incidencias_abiertas' => $incidencias,
            'estado' => $estado,
            'separacion' => [
                'acopiados' => $acopiados, 'total' => $total, 'porcentaje' => $porcentaje,
                'umbral_porcentaje' => (int) $progreso['umbral_porcentaje'],
                'cumple_umbral' => $total > 0 && $porcentaje >= (int) $progreso['umbral_porcentaje'],
                'en_anden' => $enAnden,
            ],
            'carguio' => [
                'cargados' => $cargados, 'total' => $total,
                'porcentaje' => $total > 0 ? (int) floor($cargados / $total * 100) : 0,
                'en_anden_por_cargar' => max(0, $enAnden - $cargados),
            ],
        ];
    }
}
