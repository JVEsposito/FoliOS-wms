<?php

namespace App\Enums;

enum EstadoProcesoPrefrio: string
{
    case Borrador = 'borrador';
    case Cargando = 'cargando';
    case ListoParaIniciar = 'listo_para_iniciar';
    case EnProceso = 'en_proceso';
    case PendienteVerificacion = 'pendiente_verificacion';
    case Aprobado = 'aprobado';
    case RequiereReproceso = 'requiere_reproceso';
    case Cancelado = 'cancelado';

    public function esActivo(): bool
    {
        return in_array($this, self::activos(), true);
    }

    /** @return list<self> */
    public static function activos(): array
    {
        return [
            self::Borrador,
            self::Cargando,
            self::ListoParaIniciar,
            self::EnProceso,
            self::PendienteVerificacion,
        ];
    }

    public function esTerminal(): bool
    {
        return ! $this->esActivo();
    }
}
