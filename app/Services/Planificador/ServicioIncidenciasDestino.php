<?php

namespace App\Services\Planificador;

use App\Enums\EstadoTareaMovimiento;
use App\Models\Dispositivo;
use App\Models\IncidenciaDestinoPlanificador;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deja constancia de cada destino que el servidor rechaza y de cada tarea para la que la
 * tablet no encuentra posición. Sin este registro un pallet trabado solo se conoce cuando
 * alguien lo reporta desde la planta.
 *
 * Es telemetría: un fallo al registrar nunca cambia la respuesta de la operación.
 */
final class ServicioIncidenciasDestino
{
    public function registrar(
        TareaMovimiento $tarea,
        string $origen,
        string $motivo,
        ?Posicion $posicion = null,
        ?string $plannerVersion = null,
        ?User $usuario = null,
        ?Dispositivo $dispositivo = null,
    ): ?IncidenciaDestinoPlanificador {
        try {
            return DB::transaction(fn (): ?IncidenciaDestinoPlanificador => $this->guardar(
                $tarea, $origen, $motivo, $posicion, $plannerVersion, $usuario, $dispositivo,
            ));
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    /** @return array<string, mixed> */
    public function metricas(string $temporadaId, CarbonImmutable $desde, CarbonImmutable $hasta, ?string $camaraId = null): array
    {
        $consulta = $this->ventana($temporadaId, $desde, $hasta, $camaraId);
        $porOrigen = (clone $consulta)->selectRaw('origen, SUM(repeticiones) as total')->groupBy('origen')
            ->pluck('total', 'origen')->map(fn ($total): int => (int) $total)->all();
        ksort($porOrigen);

        return [
            'registros' => (clone $consulta)->count(),
            'repeticiones' => (int) (clone $consulta)->sum('repeticiones'),
            'tareas_afectadas' => (clone $consulta)->distinct()->count('tarea_movimiento_id'),
            'por_origen' => $porOrigen,
            'motivos_frecuentes' => (clone $consulta)
                ->selectRaw('motivo, SUM(repeticiones) as total')
                ->groupBy('huella_motivo', 'motivo')
                ->orderByDesc('total')->orderBy('motivo')
                ->limit(5)
                ->get()
                ->map(fn ($fila): array => ['motivo' => $fila->motivo, 'repeticiones' => (int) $fila->total])
                ->all(),
        ];
    }

    /**
     * Tareas todavía sin ejecutar que en la última hora acumulan rechazos o avisos sin destino.
     */
    public function tareasRepetidas(?string $temporadaId, ?string $camaraId = null): int
    {
        if ($temporadaId === null) {
            return 0;
        }
        $umbral = (int) config('planificador.incidencias_destino_repetidas', 3);

        return $this->ventana($temporadaId, CarbonImmutable::now()->subHour(), CarbonImmutable::now(), $camaraId)
            ->whereHas('tareaMovimiento', fn (Builder $tarea) => $tarea->whereIn('estado', [
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
            ]))
            ->select('tarea_movimiento_id')
            ->groupBy('tarea_movimiento_id')
            ->havingRaw('SUM(repeticiones) >= ?', [$umbral])
            ->get()
            ->count();
    }

    private function guardar(
        TareaMovimiento $tarea,
        string $origen,
        string $motivo,
        ?Posicion $posicion,
        ?string $plannerVersion,
        ?User $usuario,
        ?Dispositivo $dispositivo,
    ): ?IncidenciaDestinoPlanificador {
        $temporadaId = $tarea->planOperacional?->temporada_id;
        if (! $temporadaId) {
            return null;
        }
        $motivo = Str::limit(trim($motivo) ?: 'Sin motivo informado.', 500, '');
        $huella = hash('sha256', $motivo);
        $ahora = now();
        $existente = IncidenciaDestinoPlanificador::query()
            ->where('tarea_movimiento_id', $tarea->id)
            ->where('origen', $origen)
            ->where('huella_motivo', $huella)
            ->where('ultima_at', '>=', $ahora->copy()->subMinutes((int) config('planificador.incidencias_destino_ventana_minutos', 10)))
            ->latest('ultima_at')
            ->lockForUpdate()
            ->first();
        $datos = [
            'camara_id' => $posicion?->camara_id ?? $tarea->camara_destino_id,
            'posicion_id' => $posicion?->id,
            'planner_version' => $plannerVersion,
            'user_id' => $usuario?->id,
            'dispositivo_id' => $dispositivo?->id,
            'ultima_at' => $ahora,
        ];
        if ($existente) {
            $existente->update([...$datos, 'repeticiones' => $existente->repeticiones + 1]);

            return $existente;
        }

        return IncidenciaDestinoPlanificador::create([
            ...$datos,
            'temporada_id' => $temporadaId,
            'tarea_movimiento_id' => $tarea->id,
            'maniobra_operacional_id' => $tarea->maniobra_operacional_id,
            'folio_id' => $tarea->folio_id,
            'origen' => $origen,
            'motivo' => $motivo,
            'huella_motivo' => $huella,
            'repeticiones' => 1,
            'primera_at' => $ahora,
        ]);
    }

    private function ventana(string $temporadaId, CarbonImmutable $desde, CarbonImmutable $hasta, ?string $camaraId): Builder
    {
        return IncidenciaDestinoPlanificador::query()
            ->where('temporada_id', $temporadaId)
            ->whereBetween('ultima_at', [$desde, $hasta])
            ->when($camaraId !== null, fn (Builder $consulta) => $consulta->where('camara_id', $camaraId));
    }
}
