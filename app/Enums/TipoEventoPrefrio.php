<?php

namespace App\Enums;

enum TipoEventoPrefrio: string
{
    case CargaIniciada = 'carga_iniciada';
    case PalletAgregado = 'pallet_agregado';
    case PalletRetirado = 'pallet_retirado';
    case ArmadoConfirmado = 'armado_confirmado';
    case ProcesoIniciado = 'proceso_iniciado';
    case InversionRegistrada = 'inversion_registrada';
    case Pausa = 'pausa';
    case Reanudacion = 'reanudacion';
    case Deshielo = 'deshielo';
    case Lectura = 'lectura';
    case VerificacionFinal = 'verificacion_final';
    case FinalizacionParcial = 'finalizacion_parcial';
    case RetiroParcialConfirmado = 'retiro_parcial_confirmado';
    case Aprobacion = 'aprobacion';
    case Reproceso = 'reproceso';
    case Cancelacion = 'cancelacion';
    case CorreccionAdministrativa = 'correccion_administrativa';
}
