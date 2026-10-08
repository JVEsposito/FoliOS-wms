<?php

namespace App\Enums;

enum TipoNotificacionOperacional: string
{
    case ReposicionMaterial = 'reposicion_material';
    case CargaPublicada = 'carga_publicada';
    case DespachoMaterialCreado = 'despacho_material_creado';
    case PrioridadCargaCambiada = 'prioridad_carga_cambiada';
    case IncidenciaCargaReportada = 'incidencia_carga_reportada';
    case IncidenciaCargaResuelta = 'incidencia_carga_resuelta';
    case RecepcionRomanaCreada = 'recepcion_romana_creada';
    case CierreTemporadaPendiente = 'cierre_temporada_pendiente';
}
