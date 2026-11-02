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
}
