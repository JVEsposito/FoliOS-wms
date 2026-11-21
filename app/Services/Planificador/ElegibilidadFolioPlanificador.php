<?php

namespace App\Services\Planificador;

use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Exceptions\ConflictoOperacion;
use App\Models\Folio;
use App\Models\TareaMovimiento;
use Illuminate\Database\Eloquent\Builder;

/** Una sola regla para publicar y para revalidar bajo el mutex del pallet. */
final class ElegibilidadFolioPlanificador
{
    public const TERMINALES = ['despachado', 'anulado', 'retirado_definitivo', 'agotado'];

    public const TAREAS_VIVAS = ['pendiente', 'bloqueada', 'asumida', 'en_proceso'];

    public const FUERA_DE_CAMARA = ['en_anden', 'cargado', 'despachado'];

    public static function operable(Folio $folio, bool $bloquearSalida = false): bool
    {
        return $folio->activo && ! in_array($folio->estado_operacional?->value, self::TERMINALES, true)
            && ! $folio->asignacionesCarga()->whereIn('estado', self::FUERA_DE_CAMARA)
                ->when($bloquearSalida, fn ($q) => $q->lockForUpdate())->exists();
    }

    public static function operables(Builder $q): Builder
    {
        return $q->where('folios.activo', true)->whereNotIn('folios.estado_operacional', self::TERMINALES)
            ->whereDoesntHave('asignacionesCarga', fn ($carga) => $carga->whereIn('estado', self::FUERA_DE_CAMARA));
    }

    public static function noOperables(Builder $q): Builder
    {
        return $q->where(fn ($f) => $f->where('folios.activo', false)->orWhereIn('folios.estado_operacional', self::TERMINALES)
            ->orWhereHas('asignacionesCarga', fn ($carga) => $carga->whereIn('estado', self::FUERA_DE_CAMARA)));
    }

    /** Un paso futuro inválido invalida la maniobra completa; los pasos históricos no. */
    public static function maniobras(Builder $q): Builder
    {
        return $q->whereNotIn('estado', [EstadoManiobraOperacional::Cancelada->value, EstadoManiobraOperacional::Completada->value])
            ->whereDoesntHave('pasos', fn ($pasos) => $pasos->whereIn('estado', self::TAREAS_VIVAS)
                ->whereHas('folio', self::noOperables(...)));
    }

    public static function tareas(Builder $q): Builder
    {
        return $q->whereHas('folio', self::operables(...))->where(fn ($t) => $t->whereNull('maniobra_operacional_id')
            ->orWhereHas('maniobraOperacional', self::maniobras(...)));
    }

    public static function asegurarTarea(TareaMovimiento $tarea): void
    {
        // El llamador ya tomó los mutex de folios. No confiar en relaciones ni snapshots del cliente.
        $folio = Folio::query()->lockForUpdate()->findOrFail($tarea->folio_id);
        if (! self::operable($folio, bloquearSalida: true)) {
            throw new ConflictoOperacion(self::motivo($folio).' Actualiza las tareas; este pallet no puede asignarse ni moverse.');
        }
    }

    public static function motivo(Folio $folio): string
    {
        if ($folio->estado_operacional === EstadoOperacionalFolio::Despachado) {
            return "{$folio->numero_folio} está despachado: su tarea dejó de ser operativa.";
        }
        $salida = $folio->asignacionesCarga()->whereIn('estado', self::FUERA_DE_CAMARA)->first();
        if ($salida) {
            return $salida->estado->value === 'despachado'
                ? "{$folio->numero_folio} está despachado: registra el retorno de la carga antes de ubicarlo nuevamente."
                : "{$folio->numero_folio} ya salió a andén o camión: registra su reingreso antes de ubicarlo nuevamente.";
        }

        return "{$folio->numero_folio} ya no está activo o tiene un estado final: su tarea dejó de ser operativa.";
    }

    public static function asegurarManiobra(string $maniobraId): void
    {
        $ids = TareaMovimiento::where('maniobra_operacional_id', $maniobraId)->whereIn('estado', self::TAREAS_VIVAS)->pluck('folio_id');
        $folios = Folio::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        foreach ($folios as $folio) {
            if (! self::operable($folio, bloquearSalida: true)) {
                throw new ConflictoOperacion(self::motivo($folio).' La maniobra requiere depuración antes de volver a asignarse.');
            }
        }
    }
}
