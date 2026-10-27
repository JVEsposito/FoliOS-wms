<?php

namespace App\Enums;

enum DecisionArbitrajeManiobra: string
{
    case EnEjecucion = 'en_ejecucion';
    case Seleccionada = 'seleccionada';
    case Alternativa = 'alternativa';
    case ExcluidaConflicto = 'excluida_conflicto';
    case FueraFrontera = 'fuera_frontera';
    case FueraRollout = 'fuera_rollout';
    case FueraPlanificador = 'fuera_planificador';
    /** Su paso actual no tiene hoy ningún destino posible: no ocupa cupo de la frontera. */
    case SinDestino = 'sin_destino';

    public function publicable(): bool
    {
        return in_array($this, [self::Seleccionada, self::Alternativa], true);
    }

    public function asumible(): bool
    {
        return $this === self::Seleccionada;
    }

    public function materializable(): bool
    {
        return in_array($this, [self::EnEjecucion, self::Seleccionada], true);
    }
}
