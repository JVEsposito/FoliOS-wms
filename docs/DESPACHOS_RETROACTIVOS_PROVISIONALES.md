# Despachos de materiales: fechas físicas y regularización (9/15)

Base funcional: recepciones de la etapa 8 (#499). La etapa 10 (#500, capacidad de bodegas) es independiente. Este cambio no crea almacenes ni ubicaciones.

## Qué registra

La unidad documental es **cada entrega ejecutada**, identificada por el UUID de `operaciones_retiro_materiales`. Una solicitud puede tener varias entregas parciales con fechas físicas, documentos y plazos diferentes. Crear o asignar una solicitud todavía no mueve material y no inicia un plazo.

Separa `fecha_despacho` física, `fecha_documento` de emisión y las marcas reales de procesamiento, autorización y regularización. No modifica `retirado_at`, `ocurrido_at`, la fecha de creación de la orden ni su estado operacional para simular un registro antiguo. Los clientes y entregas anteriores conservan sus UUID y hashes: no se inventan fechas físicas ni documentos históricos.

El plazo termina a las 23:59:59 del quinto día corrido posterior al día físico en la zona operacional (America/Santiago). Reutiliza el cálculo de recepciones, incluidos fines de semana y cambios de hora. Una entrega de un martes vence el domingo al terminar el día. Autorizar o regularizar no renueva el plazo.

## Uso y permisos

En **Materiales → Despachos**, escoger directo desde bodega y completar fecha/respaldo si corresponde. También se ofrece en el despacho directo desde Inventario BC. En móvil está en la confirmación de entrega desde una posición: **Fecha física y respaldo de esta entrega**. Una fecha sin offset se interpreta en la zona operacional mostrada; las fechas futuras se rechazan.

La entrega retroactiva o provisional requiere el permiso de entrega habitual y, además, `despachos.autorizar_tardios`, motivo del registro y motivo de autorización. Una autorización fuera de plazo exige reconocerlo expresamente. El permiso respeta rol, módulo y alcance del token; no se concede automáticamente a perfiles históricos sin selección explícita. Asignarlo a los supervisores designados en Accesos. No convierte a un camarero en supervisor.

La provisional permite documento y emisión pendientes. Las cantidades físicas, FIFO, reservas, disponibilidad y la sesión del camarero se verifican igual que antes. No admite cantidades desconocidas ni saldo negativo. El efecto vigente es una transferencia de custodia a un centro de costo; no es un consumo de toda la empresa.

En la lista de Oficina aparecen indicadores de entregas pendientes/vencidas de toda la temporada y filtros que incluyen órdenes completadas o canceladas. **Ver trazabilidad** muestra por entrega los folios, fechas, responsable y autorización. En móvil, **Por regularizar** incluye las órdenes cargadas con entregas pendientes; la cola móvil conserva el límite existente de las últimas 100 solicitudes. Usar Oficina con el filtro documental para recuperar pendientes más antiguos.

## Regularización documental

Desde la trazabilidad de Oficina o móvil, un usuario autorizado completa número de documento/vale, fecha de emisión y motivo. Fuera de plazo debe reconocer el atraso. Se exige versión conocida y un UUID nuevo para la regularización; reintentar la misma operación conserva el UUID. Un UUID reutilizado con otros datos o por otro responsable devuelve conflicto.

Solo se modifican el documento de la entrega y sus marcas/versiones de regularización. **No se recrean folios ni retiros, no se reponen ni descuentan saldos, no se liberan reservas y no se agregan movimientos físicos.** Puede regularizarse después de consumos posteriores o después de cancelar la parte no entregada de una solicitud. Cada entrega se regulariza por separado. Se conserva el snapshot original y un evento con documento anterior/resultante, actor, motivo, fecha y atraso. No se regularizan temporadas cerradas.

Los detalles de Supervisión, en inventario y almacenes, permiten distinguir fecha física, emisión, autorización y regularización del movimiento. Las alertas son indicadores en el módulo; no se incorporan notificaciones push ni correos nuevos.

Esto registra físicamente una operación **una sola vez** contra el saldo disponible actual. No se debe volver a registrar una entrega que ya figure ejecutada solo para añadirle fecha. La regularización no corrige cantidades ni el destino físico: las diferencias se gestionan con los movimientos auditados existentes.

## API y compatibilidad

`POST /api/materiales/despachos/directos` y `POST /api/materiales/despachos/{id}/retirar` admiten el objeto opcional `registro`:

```json
{
  "fecha_despacho": "2026-10-20T10:30:00-03:00",
  "provisional": true,
  "motivo": "Respaldo pendiente de temporada",
  "motivo_autorizacion": "Conteo físico verificado",
  "aceptar_fuera_plazo": false
}
```

Número/emisión se completan juntos mediante `numero_documento` y `fecha_documento`. El resto del payload y las reglas de entrega no cambian. Omitir `registro` conserva el circuito anterior.

`POST /api/materiales/despachos/{id}/entregas/{entregaId}/regularizar` requiere `operacion_id`, `version_conocida`, `numero_documento`, `fecha_documento` (YYYY-MM-DD), `motivo` y opcionalmente `aceptar_fuera_plazo`. Rechaza entregas de otra solicitud. La respuesta agrega `entregas` y sus evidencias sin exponer hashes.

El listado admite `regularizacion=pendiente|vencida|regularizada|retroactiva`; filtra antes del límite de solicitudes. `regularizacion.pendientes/vencidas` cuenta entregas de toda la temporada, no solamente las filas visibles; vencidas es un subconjunto de pendientes.

## Despliegue

Fusionar #499 antes de este PR; este cambio debe terminar en `main`. Respaldar BD y archivos, instalar la nueva migración, compilar Oficina y reiniciar el worker. Publicar OTA de tablet/PDA después de desplegar la API. No hay nueva dependencia nativa ni cambio de runtime: no requiere APK nueva.

Verificar primero en pruebas: entrega normal antigua, provisional parcial, autorización rechazada sin permiso, reintento, vencimiento, regularización después de consumo y conservación de saldos/movimientos. La suite cubre API, calendario, permisos, formularios y reintentos en navegador/móvil. Falta la comprobación en equipos físicos de la planta.

La migración agrega campos a las operaciones existentes y una tabla de auditoría; no reescribe inventario. Su `down` elimina la evidencia nueva: no ejecutar rollback con operaciones reales sin exportar y conciliar primero estos registros.
