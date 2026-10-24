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

/** Exclusión por pallet, compartida por todos los planes y tablets. */
class ServicioExclusionPallet
{
    public function bloquear(TareaMovimiento $tarea, bool $soloFolio = false): void
    {
        $folios = ! $soloFolio && $tarea->maniobra_operacional_id
            ? TareaMovimiento::query()->where('maniobra_operacional_id', $tarea->maniobra_operacional_id)
                ->whereIn('estado', self::estadosVivos())->pluck('folio_id')->push($tarea->folio_id)->unique()->sort()->values()
            : collect([$tarea->folio_id]);
        // Una tarea distinta tiene otra clave única. El mutex debe ser el folio,
        // también cuando aparece como paso futuro de una maniobra cerrada.
        Folio::query()->whereIn('id', $folios)->orderBy('id')->lockForUpdate()->get(['id']);
        $custodia = CustodiaTemporalManiobra::query()->whereIn('bloqueo_folio_id', $folios)
            ->when($tarea->maniobra_operacional_id, fn (Builder $q) => $q->where('maniobra_operacional_id', '!=', $tarea->maniobra_operacional_id))
            ->lockForUpdate()->first();
        if ($custodia) {
            throw new ConflictoOperacion('El pallet está bajo custodia de otra maniobra; no puede asignarse a otro camarero.');
        }
        $otras = TareaMovimiento::query()->whereIn('folio_id', $folios)->whereKeyNot($tarea->id)
            ->whereIn('estado', self::estadosVivos())
            ->when($tarea->maniobra_operacional_id, fn (Builder $q) => $q->where(fn (Builder $otra) => $otra
                ->whereNull('maniobra_operacional_id')->orWhere('maniobra_operacional_id', '!=', $tarea->maniobra_operacional_id)))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($otras as $otra) {
            if ($this->comprometidaActual($otra)) {
                throw new ConflictoOperacion('El pallet ya pertenece a otra labor tomada por un camarero o tablet. Actualiza las labores antes de continuar.');
            }
        }
    }

    /** Lecturas actuales: no autorizar con relaciones cargadas antes de adquirir el mutex. */
    private function comprometidaActual(TareaMovimiento $tarea): bool
    {
        if ($tarea->estado === EstadoTareaMovimiento::EnProceso) {
            return true;
        }
        $ids = collect([$tarea->id]);
        if ($tarea->maniobra_operacional_id) {
            $maniobra = ManiobraOperacional::query()->lockForUpdate()->find($tarea->maniobra_operacional_id);
            if ($maniobra) {
                $pasos = TareaMovimiento::query()->where('maniobra_operacional_id', $maniobra->id)
                    ->whereIn('estado', self::estadosVivos())->orderBy('id')->lockForUpdate()->get(['id', 'estado']);
                $ids = $pasos->pluck('id');
                if ($pasos->contains('estado', EstadoTareaMovimiento::EnProceso)
                    || CustodiaTemporalManiobra::query()->where('maniobra_operacional_id', $maniobra->id)
                        ->where('estado', EstadoCustodiaTemporal::Activa)->lockForUpdate()->exists()) {
                    return true;
                }
            }
        }

        return ReservaTareaMovimiento::query()->whereIn('bloqueo_tarea_id', $ids)
            ->where('estado', EstadoReservaTareaMovimiento::Activa)->where('vence_at', '>', now())
            ->lockForUpdate()->exists();
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
