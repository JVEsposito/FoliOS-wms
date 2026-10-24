# Materiales: operación entre bodegas (PR 11/15)

Cada despacho y orden de transformación tiene **una bodega física de origen**. La reserva FIFO, el retiro, la cancelación, la reversa y la devolución de componentes sin armar actúan sobre el saldo de esa bodega. Una solicitud no reúne existencias de varias bodegas: se trasladan previamente mediante Inventario CC o se crean documentos separados.

## Preparación y permisos

1. Integrar primero el PR de capacidad de bodegas (10/15) y ejecutar las migraciones normales con `php artisan migrate --force`.
2. En **Materiales → Capacidad de bodegas → Bodegas físicas**, crear el recinto. Su código es permanente y comienza sin existencias. La facultad `capacidad.configurar` permite crear y modificar; consultar capacidad no concede escritura.
3. Crear las cámaras y posiciones de Materiales mediante los permisos existentes. Transferir cantidades desde Inventario CC indicando origen, destino y ubicación física. Las dimensiones de capacidad se configuran aparte.
4. Elegir la bodega de origen al solicitar un despacho o preparar una orden. Revisar las cantidades por recinto en Inventario y la cobertura de componentes de la orden.

Las altas y ediciones guardan usuario, nombre del responsable, valores anteriores y nuevos. Los cambios usan versión y UUID: una edición obsoleta responde 409; un reintento con el mismo cuerpo no repite el alta. Un mismo UUID no admite otro cuerpo ni otro usuario.

Bodega Central permanece activa. Otra bodega solo se desactiva sin stock, reservas ni despachos u órdenes pendientes. Editar destinos desde el catálogo anterior no permite eludir estos controles.

## Existencias y operación

- Un folio puede tener saldos en varios recintos. Los botones de inventario identifican el saldo concreto; el folio por sí solo no decide la bodega.
- Las transferencias entre bodegas conservan el total de la empresa, no cuentan como consumo en reposición y no permiten trasladar cantidades reservadas.
- La entrega a un centro de costo transfiere custodia. El consumo directo de un insumo descuenta la bodega elegida e imputa el gasto al centro de costo sin crearle stock temporal.
- La tablet consulta las posiciones y cantidades del saldo de cada cámara. Las sugerencias FIFO indican la ubicación de la reserva, y el retiro exige sesión en esa cámara. El plano invalida su caché al cambiar la versión de un saldo.
- La ubicación de compatibilidad del folio sigue representando Central. Los traslados entre bodegas se hacen en Inventario CC; el movimiento general de folios continúa sujeto a esa ubicación de compatibilidad.
- Las recepciones nuevas pueden asignarse automáticamente mediante las reglas del [PR 12](materiales-asignacion-bodegas.md). Sin coincidencia conservan Central. Los folios producidos por transformación siguen ingresando inicialmente a Central y pueden transferirse después.
- Las transferencias de propiedad entre clientes conservan su circuito de Central y su permiso específico.

## Compatibilidad y controles

Los documentos anteriores mantienen `almacen_origen_id = NULL`, que se interpreta como Central. Los clientes anteriores que no envían el campo conservan ese origen; no se reasignan documentos históricos ni se redistribuye stock durante la migración. El API añade origen y saldos sin quitar campos existentes.

Las cantidades globales se recalculan desde los saldos concretos bajo bloqueo del folio. Las lecturas actuales evitan reconstruir un total con una fotografía anterior de otra transacción en MySQL REPEATABLE READ. No se admite stock negativo ni una reserva mayor que la existencia. Un saldo físicamente ubicado puede utilizarse aunque otro saldo del mismo folio esté pendiente de ubicación; vencimientos y bloqueos siguen impidiendo reservar.

La validación cubre un folio repartido entre Central y Norte, reserva/cancelación exacta, retiro parcial en tablet con reintento, consumo con centro de costo, devolución y reversa de transformación, vencimiento sin reasignación entre bodegas y reservas concurrentes. Las pruebas de navegador cubren permisos, altas con reintento, actualización tras conflicto de versión, origen del despacho y cobertura por bodega de las órdenes.
