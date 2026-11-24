<?php

namespace App\Enums;

enum EstadoLoteMateriaPrima: string
{
    case Borrador = 'borrador';
    case PendienteHidrocooler = 'pendiente_hidrocooler';
    case HidrocoolerEnCurso = 'hidrocooler_en_curso';
    case HidrocoolerRetenido = 'hidrocooler_retenido';
    case PendienteAsignacion = 'pendiente_asignacion';
    case DisponibleProceso = 'disponible_proceso';
    case AsignadoCamara = 'asignado_camara';
    case EntregaParcialProceso = 'entrega_parcial_proceso';
    case EntregadoProceso = 'entregado_proceso';
    case Anulado = 'anulado';

    /** Los textos de la oficina de materia prima. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::PendienteHidrocooler => 'Pendiente hidrocooler',
            self::HidrocoolerEnCurso => 'Hidrocooler en curso',
            self::HidrocoolerRetenido => 'Retenido por Hidrocooler',
            self::PendienteAsignacion => 'Pendiente de cámara',
            self::DisponibleProceso => 'Disponible directo a proceso',
            self::AsignadoCamara => 'Asignado a cámara',
            self::EntregaParcialProceso => 'Entrega parcial a Packing',
            self::EntregadoProceso => 'Entregado a Packing',
            self::Anulado => 'Anulado',
        };
    }

    public function esEditable(): bool
    {
        return $this === self::Borrador;
    }
}
