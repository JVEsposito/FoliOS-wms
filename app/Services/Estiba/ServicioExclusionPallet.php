<?php

namespace App\Services\Estiba;

use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoReservaTareaMovimiento;
use App\Enums\EstadoTareaMovimiento;
use App\Exceptions\ConflictoOperacion;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Exclusión por pallet, compartida por todos los planes y tablets. */
class ServicioExclusionPallet
{
    /** Mutex común antes de bloquear una tarea, incluso al renovar o liberar. */
    public function bloquearFolios(TareaMovimiento $tarea, bool $soloFolio = false): Collection
    {
        $folios = ! $soloFolio && $tarea->maniobra_operacional_id
            ? TareaMovimiento::query()->where('maniobra_operacional_id', $tarea->maniobra_operacional_id)
                ->whereIn('estado', self::estadosVivos())->pluck('folio_id')->push($tarea->folio_id)->unique()->sort()->values()
            : collect([$tarea->folio_id]);
        // Una tarea distinta tiene otra clave única. El mutex debe ser el folio,
        // también cuando aparece como paso futuro de una maniobra cerrada.
        Folio::query()->whereIn('id', $folios)->orderBy('id')->lockForUpdate()->get(['id']);

        return $folios;
    }

    public function bloquear(TareaMovimiento $tarea, bool $soloFolio = false): void
    {
        $folios = $this->bloquearFolios($tarea, $soloFolio);
        $custodia = CustodiaTemporalManiobra::query()->whereIn('bloqueo_folio_id', $folios)
            ->when($tarea->maniobra_operacional_id, fn (Builder $q) => $q->where('maniobra_operacional_id', '!=', $tarea->maniobra_operacional_id))
            ->lockForUpdate()->first();
        if ($custodia) {
            throw new ConflictoOperacion('El pallet está bajo custodia de otra maniobra; no puede asignarse a otro camarero.');
        }
        foreach ($folios as $folioId) {
            foreach ($this->tareasActuales($folioId) as $otra) {
                if ($otra->id === $tarea->id || ! in_array($otra->estado, self::estadosVivos(), true)
                    || ($tarea->maniobra_operacional_id !== null && $otra->maniobra_operacional_id === $tarea->maniobra_operacional_id)) {
                    continue;
                }
                if ($this->comprometidaActual($otra)) {
                    throw new ConflictoOperacion('El pallet ya pertenece a otra labor tomada por un camarero o tablet. Actualiza las labores antes de continuar.');
                }
            }
        }
    }

    /** Lectura actual bajo el mutex del folio, sin recorrer índices que cambian al tomar o liberar. */
    public function tareasActuales(string $folioId): Collection
    {
        // Mantener solo la igualdad del folio en SQL. Filtrar estados o excluir la
        // tarea antes de bloquear puede convertir esta búsqueda en un recorrido
        // de rangos que alcance filas de otros pallets, incluso con FORCE INDEX.
        return TareaMovimiento::query()->where('folio_id', $folioId)
            ->when(DB::getDriverName() === 'mysql', fn ($q) => $q->forceIndex('tareas_folio_mutex_idx'))
            ->orderBy('id')->lockForUpdate()->get();
    }

    /** Requiere la tarea actual bloqueada y el mutex de su folio. */
    public function reservaActual(TareaMovimiento $tarea): ?ReservaTareaMovimiento
    {
        $consulta = ReservaTareaMovimiento::query()->where('bloqueo_tarea_id', $tarea->id);
        if ($tarea->responsable_user_id !== null
            || in_array($tarea->estado, [EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso], true)) {
            // La tarea actual anuncia un claim: leerlo aunque el snapshot sea anterior.
            return $consulta->lockForUpdate()->first();
        }

        // Una tarea pendiente sin actor no tiene un claim publicado. Bloquear una
        // clave ausente retendría el hueco donde otros pallets crean sus reservas.
        // Si el snapshot conserva una reserva histórica, comprobar su PK actual.
        $id = $consulta->value('id');
        $reserva = $id ? ReservaTareaMovimiento::query()->lockForUpdate()->find($id) : null;

        return $reserva?->bloqueo_tarea_id === $tarea->id ? $reserva : null;
    }

    /** Lecturas actuales: no autorizar con relaciones cargadas antes de adquirir el mutex. */
    private function comprometidaActual(TareaMovimiento $tarea): bool
    {
        if ($tarea->estado === EstadoTareaMovimiento::EnProceso) {
            return true;
        }
        $pasos = collect([$tarea]);
        if ($tarea->maniobra_operacional_id) {
            $maniobra = ManiobraOperacional::query()->lockForUpdate()->find($tarea->maniobra_operacional_id);
            if ($maniobra) {
                $pasos = TareaMovimiento::query()->where('maniobra_operacional_id', $maniobra->id)
                    ->whereIn('estado', self::estadosVivos())->orderBy('id')->lockForUpdate()->get(['id', 'estado', 'responsable_user_id']);
                if ($pasos->contains('estado', EstadoTareaMovimiento::EnProceso)
                    || CustodiaTemporalManiobra::query()->where('maniobra_operacional_id', $maniobra->id)
                        ->where('estado', EstadoCustodiaTemporal::Activa)->lockForUpdate()->exists()) {
                    return true;
                }
            }
        }

        return $pasos->contains(function (TareaMovimiento $paso): bool {
            $reserva = $this->reservaActual($paso);

            return $reserva?->estado === EstadoReservaTareaMovimiento::Activa && $reserva->vence_at->gt(now());
        });
    }

    /** Filtro de publicación; la escritura vuelve a comprobarlo bajo el mutex del folio. */
    public static function conCompromiso(Builder $consulta): void
    {
        $consulta->where(function (Builder $q): void {
            $q->where('estado', EstadoTareaMovimiento::EnProceso)
                ->orWhereHas('reservaActiva', fn (Builder $r) => $r->conCompromisoVigente())
                ->orWhereHas('maniobraOperacional', fn (Builder $m) => $m
                    ->where(function (Builder $viva): void {
                        $viva->whereHas('pasos.reservaActiva', fn (Builder $r) => $r->conCompromisoVigente())
                            ->orWhereHas('custodiasTemporales', fn (Builder $c) => $c->where('estado', EstadoCustodiaTemporal::Activa));
                    }));
        });
    }

    private static function estadosVivos(): array
    {
        return [EstadoTareaMovimiento::Bloqueada, EstadoTareaMovimiento::Pendiente,
            EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso];
    }
}
