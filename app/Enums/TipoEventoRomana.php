<?php

namespace App\Enums;

enum TipoEventoRomana: string
{
    case IngresoRegistrado = 'ingreso_registrado';
    case IngresoActualizado = 'ingreso_actualizado';
    case CorreccionAdministrativa = 'correccion_administrativa';
    case IngresoConfirmado = 'ingreso_confirmado';
    case PesajeEnvasesRegistrado = 'pesaje_envases_registrado';
    case PesajeEnvasesAnulado = 'pesaje_envases_anulado';
    case RecepcionCerrada = 'recepcion_cerrada';
    case EspecieCompletada = 'especie_completada';
}
