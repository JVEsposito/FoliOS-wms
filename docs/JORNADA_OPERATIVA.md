# Jornada operativa de planta

En **Administración → Accesos & Temporadas → Turnos operativos**, una cuenta con permiso de administración de accesos programa el horario y su primera fecha de vigencia. El formulario propone **A de 07:00 a 19:00** y **B de 19:00 a 07:00**. El relevo usa la zona de planta `APP_OPERATIONAL_TIMEZONE` (`America/Santiago` por defecto). Con esos horarios un evento del 14 de noviembre a las 06:59:59 corresponde a la jornada del 13 y al turno B. A las 07:00 comienza el turno A del 14.

Se conservan las versiones publicadas, el administrador y la fecha de registro del cambio. Una vigencia pendiente se puede ajustar antes de su inicio. Los filtros usan un inicio incluido y un término excluido. Se calculan ambos en hora local y se convierten a UTC, por lo que el corte mantiene su hora durante el cambio estacional.

Antes de la primera activación se aplica el criterio histórico de cada módulo. En la fecha de transición, los registros previos al inicio conservan su criterio antiguo y el nuevo turno se aplica desde la hora programada. Los cambios siguientes entran al inicio del nuevo horario y delimitan también el fin de la jornada anterior.

| Flujo | Evento de referencia |
| --- | --- |
| Ingresos de Portería y recepciones de Romana | Ingreso efectivo. El aviso conserva también la salida o destare. |
| Validación PT | Captura en el dispositivo. La sincronización y el turno declarado siguen disponibles para trazabilidad. |
| Validación MP | Fecha y hora de validación. |
| Prefrío, punto de rocío e Hidrocooler | Inicio térmico, medición o término según el registro o indicador. |
| Repaletizaje | Confirmación para las nuevas jornadas. El turno declarado se conserva en el expediente. |
| Movimientos de cámaras MP, materiales y envases | Fecha y hora efectiva del movimiento. |
| Despacho y verificación de cámaras | Ventana operativa vigente para bandejas y rondas. Los registros conservan sus horas efectivas. |
| Calendario de embarques, fecha de cosecha o embalaje, documentos externos y contabilidad | Fechas calendario o documentales propias de cada dato. |

El servidor conserva UTC y entrega la fecha operativa separada del instante original. Los informes operativos que muestran turno conservan la fecha u hora real y agregan la clasificación operativa. El calendario alimenta los horarios sugeridos de las labores de camareros; sus responsables continúan configurándose en Labores por turno.

## Despliegue

Ejecutar la migración y compilar los recursos de Oficina. Publicar OTA para tablets y PDA para que Validación PT pueda mostrar la jornada configurada incluso con su sesión en caché. Finalmente programar la primera vigencia en Oficina. La migración instala el horario sugerido con la vigencia pendiente, sin recalcular registros o documentos históricos.
