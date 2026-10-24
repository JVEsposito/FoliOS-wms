# Materiales 12/15: asignación de bodega al aprobar recepciones

En **Materiales → Capacidad de bodegas → Asignación automática al aprobar recepciones**, configurar reglas con bodega, cliente opcional, tipo operacional opcional y prioridad. El número menor se evalúa primero; las reglas activas no comparten prioridad. Todos los criterios declarados deben coincidir. Cada línea puede ir a una bodega distinta; sus bultos conservan esa asignación.

La aprobación genera el saldo inicial directamente en la bodega elegida, con cámara y posición pendientes. No registra una transferencia ficticia ni habilita reservas antes de ubicar. Sin coincidencia activa se conserva Bodega Central, con razón explícita `central_por_defecto`. Bodegas virtuales o inactivas no reciben nuevas asignaciones.

La configuración reutiliza `capacidad.configurar`; la consulta exige acceso al inventario. Cada edición conserva responsable, hora, motivo, antes/después y versión. Reintentos con el mismo UUID y cuerpo no duplican reglas. Otro cuerpo o responsable con ese UUID se rechaza. Las reglas se leen bajo bloqueo compartido dentro de la transacción de confirmación, y las escrituras se serializan en una fila de configuración.

## Alcance físico

La asignación determina la **bodega de ingreso**, no una posición ni espacio libre certificado. Las dimensiones de referencia del PR 10 no descuentan ocupación ni mezclas de formatos, y una recepción no declara dimensiones reales por bulto. Por eso se conserva `capacidad=pendiente_verificacion`; no se inventa un cálculo de espacio libre a partir de cajas o kilos. Se debe verificar capacidad antes de confirmar cámara/posición en terreno. Una bodega que no puede recibir material se desactiva cuando esté vacía, o se desactiva su regla para nuevas recepciones.

La decisión queda congelada en la ficha del material, el snapshot de confirmación y el movimiento de ingreso, con nombre/versión de regla, bodega, hora y responsable. Es evidencia histórica: editar reglas o renombrar bodegas no redistribuye folios confirmados. La ubicación actual se consulta en Inventario. Oficina muestra la asignación por folio; la app la muestra en recepción, pendientes y al buscar/escanear para ubicar.

## Compatibilidad

`almacen_inicial_id=NULL` mantiene Central para inventario histórico, producción y otras altas anteriores. No hay backfill ni redistribución. En folios de recepciones nuevas la ubicación de compatibilidad sigue su bodega inicial: primera ubicación, cambio de ubicación y liberación actualizan ese saldo. Las demás bodegas de un folio repartido conservan el circuito de Inventario CC del PR 11. Despachos, cancelaciones y transformaciones siguen usando la bodega del documento.

El circuito antiguo de transferencia de propiedad opera exclusivamente con Central. Se rechaza si el folio tiene una bodega inicial distinta, incluso cuando también posee cantidades en Central, para impedir que el observer de compatibilidad debite una bodega equivocada. La ampliación de transferencia de propiedad por cualquier origen requiere un cambio específico.

Se conservan la foto documental, autorizaciones de cinco días, bloqueo de material, versión e idempotencia de recepción. Una anulación válida antes de ubicar deja en cero la bodega inicial. La eliminación administrativa conserva la decisión en su evidencia y elimina los saldos por sus cascadas existentes.

## Despliegue y pruebas

1. Integrar sobre el `main` que incluye #510, respaldar las bases y aplicar `php artisan migrate --force`.
2. Compilar Oficina con `npm ci` y `npm run build`. Reiniciar workers según el procedimiento habitual.
3. Después de fusionar, publicar OTA tablet/PDA para ver las indicaciones nuevas. No cambian dependencias nativas ni runtime: no se requiere APK nueva por este PR.
4. En pruebas, crear Norte y una regla para un cliente/tipo; aprobar recepción con dos bultos. Comprobar saldo total correcto en Norte, cero reserva, sin cámara/posición, y decisión por folio.
5. Ubicar desde un dispositivo autorizado; verificar que aparece el folio en el plano y que el saldo localizado pertenece a Norte. Despachar desde Norte y cancelar un remanente verificando su reserva.
6. Cambiar la regla y comprobar que la recepción anterior conserva su decisión. Desactivar reglas y confirmar otra recepción: debe quedar en Central por defecto. Probar anulación antes de ubicar, lectura sin permisos de edición y reintento tras perder respuesta.

El rollback de esquema elimina las reglas y el enlace de bodega inicial; no debe ejecutarse sobre operación ya asignada a recintos secundarios. Para volver a una versión anterior se requiere conciliar esos saldos o restaurar el respaldo correspondiente.
