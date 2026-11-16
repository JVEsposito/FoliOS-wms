# Armado y edición de lotes de inspección SAG

En **Frigorífico → Inspección SAG → Nueva inspección**, cada pallet muestra sus
cajas, CSG (incluidos los componentes de un mixto), condición térmica y ubicación.
Si sigue en un túnel, se muestra el código del túnel, su posición y el proceso.
La ubicación actual en cámara prevalece sobre procesos de prefrío históricos;
un retiro registrado deja de mostrarse como presencia en túnel.

Después de crear el lote, abre **Ver detalle** mientras esté en **Preparación**:

- **Buscar y agregar** permite escribir o escanear un folio elegible del mismo cliente.
- **Quitar del lote** retira un pallet y sus resultados pendientes. Debe quedar al menos uno.
- El detalle también presenta cajas, CSG y ubicación de cada miembro.

La edición requiere el permiso de gestión de inspecciones SAG. El perfil de
consulta conserva la lectura. Al iniciar la inspección o registrar resultados,
la composición del lote deja de ser editable.

Cada modificación registra una transición con los miembros agregados y retirados
y sus cambios de auditoría. Se mantienen las autorizaciones SAG anteriores de los
folios y los destinos del lote. Las reservas de espacio y las tareas reversibles
se reajustan a la nueva cantidad; los folios retirados recuperan su circuito de
recepción cuando corresponde. Una maniobra iniciada o con custodia impide la
edición completa: se debe finalizar o resolver esa maniobra antes de reintentarlo.

Editar el lote no registra movimientos físicos, no aprueba el prefrío y no cambia
las cantidades de cajas de los pallets.
