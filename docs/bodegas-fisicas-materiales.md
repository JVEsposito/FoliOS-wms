# Bodegas físicas de materiales

Etapa 11. Oficina → Materiales → Bodegas físicas permite crear, editar y desactivar recintos. Los centros de costo continúan separados.

Cada despacho tiene una bodega de origen fija. Los clientes anteriores que omiten el origen continúan en Bodega Central. Las reservas y su liberación al cancelar corresponden al saldo de esa bodega. El procesamiento de vencimientos tampoco cambia el origen.

Los traslados requieren permiso bodegas.trasladar, motivo y versiones de ambos saldos. Conservan el total de empresa y registran origen, destino, cantidad y responsable. No se traslada cantidad reservada ni material vencido. Una estiba abierta bloquea traslados físicos desde Oficina.

Las bodegas sin plano entregan desde la orden móvil, confirmando folio FIFO y cantidad. Las bodegas con plano conservan cámara y sesión de estiba. Un folio mantiene una sola ubicación física en el plano: para cambiar entre dos bodegas con plano se mueve todo el saldo ubicado. Se pueden separar cantidades hacia bodegas sin plano.

El catálogo requiere bodegas.administrar. Los perfiles históricos necesitan selección explícita de las acciones nuevas. No se desactiva ni cambia el modo de una bodega con existencias, reservas u órdenes pendientes. Bodega Central conserva su activación y modo. Los cambios registran motivo, usuario, antes/después y versión.

La cubicación y los tipos de pallet se configuran en Capacidad de bodegas. Esta etapa no asigna recepciones automáticamente: siguen ingresando en Central; la asignación automática corresponde a la etapa 12.

## Despliegue y prueba

Aplicar la migración 2026_10_28_120000, compilar Oficina y publicar OTA para tablet/PDA después de fusionar. No hay dependencias nativas nuevas. Respaldar las bases antes de migrar. No revertir la funcionalidad eliminando evidencia de entregas: cámara puede ser nula en retiros de bodegas sin plano.

Probar en servidor de pruebas: crear bodega sin plano, trasladar parte de un folio desde Central, comprobar total inalterado, crear despacho desde esa bodega, entregar parcialmente en dispositivo y cancelar el remanente. Verificar saldo reservado cero en origen, stock entregado en centro de costo y stock de Central inalterado por la entrega. Repetir la petición con el mismo UUID no duplica el movimiento. Configurar dimensiones y revisar capacidad mediante el enlace de cada bodega.
