<?php

namespace App\Finanzas\Cierre;

use App\Finanzas\Models\Cierre;
use App\Finanzas\Periodo;

/**
 * Orden de los cierres. Los meses se cierran en orden: el primer cierre marca el inicio y
 * desde ahí se cierra el mes siguiente al último cerrado. Solo se reabre el último, así que los
 * meses cerrados siempre forman un tramo continuo y el primer mes abierto está bien definido:
 * ahí se cobran las diferencias aceptadas.
 */
final class CalendarioCierres
{
    /** El cierre que rige un mes, si está cerrado. */
    public function vigente(string $mes): ?Cierre
    {
        return Cierre::query()->where('mes', $mes)->where('estado', Cierre::ESTADO_CERRADO)->first();
    }

    public function ultimo(): ?Cierre
    {
        return Cierre::query()->where('estado', Cierre::ESTADO_CERRADO)->orderByDesc('mes')->first();
    }

    /** Mes siguiente al último cerrado; nulo si no hay meses cerrados. */
    public function primerMesAbierto(): ?string
    {
        $ultimo = $this->ultimo();

        return $ultimo === null ? null : Periodo::mes($ultimo->mes)->siguiente()->clave();
    }

    /** Por qué no se puede cerrar el mes, o nulo si se puede. */
    public function impedimento(string $mes): ?string
    {
        $periodo = Periodo::mes($mes);
        if (! $periodo->terminado()) {
            return 'El mes aún no termina: se puede cerrar desde el '.$periodo->fin->addDay()->format('d-m-Y').'.';
        }
        if ($this->vigente($mes) !== null) {
            return 'El mes ya está cerrado.';
        }

        $ultimo = $this->ultimo();
        if ($ultimo === null) {
            return null;
        }
        $siguiente = Periodo::mes($ultimo->mes)->siguiente();
        if ($siguiente->clave() === $mes) {
            return null;
        }
        if ($mes > $ultimo->mes) {
            return 'Los meses se cierran en orden: primero cierre '.$siguiente->nombre().'.';
        }
        $primero = (string) Cierre::query()->where('estado', Cierre::ESTADO_CERRADO)->min('mes');

        return 'Los meses se cierran en orden desde el primer cierre ('.Periodo::mes($primero)->nombre().'): este mes queda antes y no se cierra.';
    }

    /** Por qué no se puede reabrir el mes, o nulo si se puede. */
    public function impedimentoReapertura(string $mes): ?string
    {
        $vigente = $this->vigente($mes);
        if ($vigente === null) {
            return 'El mes no está cerrado.';
        }
        $ultimo = $this->ultimo();
        if ($ultimo !== null && $ultimo->id !== $vigente->id) {
            return 'Solo se reabre el último mes cerrado: '.Periodo::mes($ultimo->mes)->nombre().'.';
        }

        return null;
    }
}
