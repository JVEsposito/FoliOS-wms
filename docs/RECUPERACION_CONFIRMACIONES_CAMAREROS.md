# Recuperación de confirmaciones y deadlocks de estiba

Un retorno de banda podía quedar en Confirmación pendiente aunque el servidor no hubiese registrado la entrega. Un deadlock real revierte toda la transacción de MySQL. El motor de auditoría podía escribir después una transición fallida con ese UUID; al reenviar, lanzaba el texto SQL guardado como excepción de dominio. Laravel reconocía la palabra de deadlock en ese texto como si fuese un nuevo fallo de MySQL y el intento se mantenía bloqueado.

## Comportamiento corregido

- Los conflictos transitorios suben hasta la transacción exterior para reintentar el comando completo. No se crea una transición final por ese rollback técnico.
- Si se agotan los reintentos, la API devuelve 503 y Retry-After, con mensaje operacional y sin SQL. La tablet conserva la captura y el UUID.
- Una transición fallida anterior permanece inmutable. Repetir su UUID devuelve un rechazo normal 409, sin volver a lanzar el mensaje SQL histórico. La tablet consulta el estado propio: si la tarea ya terminó, retira la captura; si el registro se rechazó y la tarea sigue en proceso, deja volver a confirmar con una nueva operación. No marca terminada ni libera la custodia por ese rechazo.
- Mientras una confirmación es incierta, la tablet conserva las sesiones del envío. Al verificar el resultado se cierran las que abrió temporalmente. El panel muestra el identificador de operación para soporte.
- La validación de exclusión consulta primero los pasos del pallet y bloquea solo las maniobras correspondientes por ID, en vez del rango de todas las maniobras activas. Se mantienen la exclusión por pallet, las reservas y las custodias.

## Caso de prueba informado

Servidor folios_pruebas, folio 1000000012, retorno de banda en proceso y custodia activa. El retiro previo aceptado no acredita el retorno. Se debe recuperar el registro, conservando el retiro y la evidencia histórica. No borrar folios, custodias, transiciones ni el almacenamiento de la app.

## Despliegue y recuperación

No agrega migraciones. Actualizar el servidor, limpiar cachés y reiniciar workers. Publicar OTA para tablets en production y camaras-pruebas (runtime 1.5.0, sin otro APK). El arreglo de los UUID fallidos y el bloqueo de consulta están en el servidor; la OTA mejora el mensaje y conserva las sesiones ante una respuesta incierta.

En la tablet afectada, pulsar Revisar confirmación. Si ya se aplicó la entrega, se recupera sin otro movimiento. Si aparece el rechazo del intento histórico, la captura local se retira tras consultar al servidor y la labor sigue en proceso: volver a confirmar el registro con los datos vigentes, sin repetir el movimiento físico. Una discrepancia real debe resolverse por el circuito supervisado. En pruebas con un pallet simulado puede completarse el flujo para validar el registro.
