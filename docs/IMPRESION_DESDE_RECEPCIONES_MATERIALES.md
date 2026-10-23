# Imprimir folios desde Recepciones

PR 6 del plan de Materiales. En Oficina → Materiales → Recepciones:

- Al confirmar aparece un aviso con **Imprimir folios**. Si se confirma desde el formulario, la misma acción queda disponible en el formulario confirmado.
- El detalle de una recepción confirmada también muestra **Imprimir folios**.
- La acción abre la pantalla existente de Etiquetas con esa recepción seleccionada, aunque no esté entre las primeras cien del listado. No vuelve a confirmar la recepción ni genera existencias.
- Se pueden seleccionar todos los folios o algunos, el perfil de impresora, PDF o NiceLabel/ZebraDesigner, la simbología compatible y las copias. **Volver a recepciones** permite regresar al listado.

El acceso exige `imprimir-etiquetas-materiales`, como la pantalla y API anteriores. El botón se muestra según `puede_imprimir_etiquetas_materiales`; borradores y recepciones anuladas no lo muestran. No exige acceso a Transformaciones para imprimir una recepción. La API conserva las comprobaciones de temporada, origen de los folios, formato, copias y reimpresión.

Cada generación conserva la auditoría existente: usuario, fecha, perfil y contenido de la etiqueta, folios, copias y motivo de reimpresión. Volver a generar un folio exige motivo y se identifica como reimpresión. Una descarga se registra como archivo generado; la impresión física se realiza después desde ese archivo.

Dentro de la misma pantalla, los reintentos de una selección conservan el identificador de operación hasta recibir el archivo. El formulario impide envíos simultáneos; cambiar selección, formato, copias o motivo inicia otra operación. Un error al refrescar el historial después de descargar se informa por separado, para no repetir una descarga que ya terminó.

Este PR reutiliza las rutas, el motor y las tablas de impresión actuales. No requiere migraciones ni una APK nueva: los cambios son de Oficina. Puede integrarse desde main sin depender de los PR abiertos de catálogos, unidades, transportistas o permisos; el permiso de impresión del PR 5 sigue aplicándose al integrarlo.

Validación: confirmación → impresión precargada en escritorio y pantalla pequeña; selección parcial; recepción fuera de la primera página; perfil sin Transformaciones; reintento con la misma operación; reimpresión con motivo. La prueba de API comprueba folios auditados y copias, repetición idempotente y conservación de existencias, movimientos y versión de la recepción.
