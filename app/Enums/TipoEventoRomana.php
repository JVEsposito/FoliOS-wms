<?php

namespace App\Enums;

enum TipoEventoRomana: string
{
    case IngresoRegistrado = 'ingreso_registrado';
    case IngresoActualizado = 'ingreso_actualizado';
    case CorreccionAdministrativa = 'correccion_administrativa';
    case PesoBrutoCorregido = 'peso_bruto_corregido';
    case IngresoConfirmado = 'ingreso_confirmado';
    case PesajeEnvasesRegistrado = 'pesaje_envases_registrado';
    case PesajeEnvasesAnulado = 'pesaje_envases_anulado';
    case SalidaEnvasesCorregida = 'salida_envases_corregida';
    case RecepcionCerrada = 'recepcion_cerrada';
    case RecepcionAnulada = 'recepcion_anulada';
    case RecepcionEliminada = 'recepcion_eliminada';
    case EspecieCompletada = 'especie_completada';
    case CorreccionIntegral = 'correccion_integral';
    // Pesaje acumulativo: el neto por envase se rehízo con el conteo de Validación MP (vacíos incluidos).
    case NetoPorEnvaseAjustado = 'neto_por_envase_ajustado';
    // Administración ajustó solo la cuenta corriente de envases (sin tocar guías, lotes ni kilos).
    case AjusteCuentaEnvases = 'ajuste_cuenta_envases';
}
