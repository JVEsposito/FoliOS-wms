<?php

namespace App\Enums;

enum TipoEventoCarga: string
{
    case Creada = 'creada';
    case Actualizada = 'actualizada';
    case FolioAsignado = 'folio_asignado';
    case FolioSegregado = 'folio_segregado';
    case FolioDesasignado = 'folio_desasignado';
    case Publicada = 'publicada';
    case TareasGeneradas = 'tareas_generadas';
    case FolioMovido = 'folio_movido';
    case IncidenciaReportada = 'incidencia_reportada';
    case IncidenciaResuelta = 'incidencia_resuelta';
    case FolioReemplazado = 'folio_reemplazado';
    case FolioEnviadoAnden = 'folio_enviado_anden';
    case FolioCargado = 'folio_cargado';
    case FolioDescargado = 'folio_descargado';
    case FolioDespachado = 'folio_despachado';
    case CierreDespacho = 'cierre_despacho';
    case DespachoDirectoPrefrio = 'despacho_directo_prefrio';
    case CamionEnAnden = 'camion_en_anden';
    case CamionFueraAnden = 'camion_fuera_anden';
    case Cancelada = 'cancelada';
    case Retornada = 'retornada';
}
