<?php

namespace App\Services\Asistente;

use App\Models\Temporada;

/**
 * Temporadas que usa el asistente. «Temporada pasada» es siempre la productiva anterior
 * a la activa: las temporadas de prueba nunca cuentan como historia.
 */
class TemporadasAsistente
{
    public function anterior(Temporada $activa): ?Temporada
    {
        return Temporada::query()->productivas()
            ->where('id', '!=', $activa->id)
            ->when(
                $activa->fecha_inicio !== null,
                fn ($q) => $q->where(fn ($q) => $q->where('fecha_inicio', '<', $activa->fecha_inicio->toDateString())
                    ->orWhere(fn ($q) => $q->whereNull('fecha_inicio')->where('created_at', '<', $activa->created_at))),
                fn ($q) => $q->where('created_at', '<', $activa->created_at),
            )
            ->orderByRaw('fecha_inicio IS NULL')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('created_at')
            ->first();
    }

    /** Primera jornada de la temporada (Y-m-d): su fecha de inicio o, sin ella, el día en que se creó. */
    public function primeraJornada(Temporada $temporada): string
    {
        return ($temporada->fecha_inicio ?? $temporada->created_at)->toDateString();
    }

    /** Última jornada (Y-m-d), o null si la temporada sigue abierta. */
    public function ultimaJornada(Temporada $temporada): ?string
    {
        return $temporada->fecha_fin?->toDateString();
    }
}
