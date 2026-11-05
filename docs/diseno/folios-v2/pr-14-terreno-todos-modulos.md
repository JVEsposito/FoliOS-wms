# Terreno: base común para PDA y tablet

Esta entrega extiende el criterio de la tablet del camarero a los 13 módulos que admite la app. La pantalla debe dejar claro qué se está trabajando y cómo continuar, conservando los controles de negocio, los datos de captura y las comprobaciones antes de salir.

## Reglas comunes

- Una cabecera de módulo con el gajo del área, título corto y acción contextual (actualizar o sincronizar). Identidad, dispositivo, cambio de módulo y cierre de sesión se agrupan en el menú del operador.
- Se retira la franja adicional «Módulo activo / Cambiar». El cambio sigue condicionado a los módulos autorizados y a la variante del equipo; no se agregan permisos ni módulos a la PDA.
- El menú llama al cierre propio de cada pantalla: se conservan las advertencias de captura sin guardar, bandeja pendiente o turno abierto. Queda deshabilitado cuando la pantalla bloqueaba la salida durante una escritura.
- Inter para lectura; JetBrains Mono para folios, códigos y números operativos. Cada peso usa el archivo de fuente correspondiente; se eliminan los `fontWeight` locales que Android no resolvía sobre fuentes cargadas.
- Controles secundarios con superficie táctil de al menos 44 dp donde se definen mínimos locales; entradas de al menos 52 dp y acciones primarias del kit de 56 dp. Las acciones conservan su habilitación y callback originales.
- El estado sin conexión permanece visible. Temporada y versión de catálogo siguen disponibles; en Validación PT se mantiene la fila compacta de catálogo, línea/turno y sincronización.
- Errores, incidencias y confirmaciones pendientes conservan su visibilidad. No se convierten en avisos transitorios ni se cierran automáticamente.

## Aplicación por módulo

| Módulo | Dato y acción que se mantienen en primer plano | Secundario agrupado |
| --- | --- | --- |
| Cámaras y camareros | Folio, origen/destino y tomar/continuar la maniobra | Cola, cargas y menú del operador |
| Operación de materiales | Verificación, conteo e inventario autorizado | Identidad y cambio de módulo, con gajo de Materiales |
| Validación PT | Escaneo, producto/origen y decisión de validación | Identidad en menú; estado del catálogo conservado |
| Validación MP | Recepción, conteo, tarjas y segregación | Identidad en menú; actualizar en cabecera |
| Repaletizaje | Folios de origen, compatibilidad, composición y resultado | Historial existente y menú |
| Fruta embalada | Recepción y captura del pallet | Salida con advertencia si hay cambios sin guardar |
| Prefrío | Pendientes, túnel/proceso, posición y lectura de ingreso | Sincronización en cabecera; bandeja pendiente conservada |
| Fruta a proceso | Órdenes, viaje físico y retorno | Temporada y actualizar en cabecera |
| Hidrocooler MP | Proceso, lote y lecturas requeridas | Resumen y acciones contextuales existentes |
| Recepción de materiales | Documento, detalle, cantidades, bultos y confirmación | Temporada y actualizar; historial en sus pestañas |
| Portería | Cliente, patente, guía y registro de ingreso | Identidad y dispositivo en menú; bitácora conservada |
| Contraparte SAG | Documento, pallets, actividad y registro | Identidad en menú; consulta de documentos existente |
| Administración demo | Datos maestros y ejemplos locales | Indicación LOCAL y salida en menú |

Acceso, selección de módulo, cambio de contraseña, impresión, fotos e inspección RC-02 adoptan la misma tipografía. No cambian sus validaciones ni formatos de impresión.

## Ajustes de la tablet de camareros

- La portada cambia a una columna por debajo de 1000 dp. La tarjeta principal no crece para igualar la altura de toda la cola: su botón queda inmediatamente después del recorrido.
- El selector de nivel permite explorar otro nivel aunque haya una posición seleccionada. Solo sigue la posición al cambiar la selección.
- Ambiente muestra «Estado sin verificar» cuando falla la consulta, y no declara todas las cámaras al día sin datos.
- Las estibas propias abiertas se comunican al espacio de trabajo y se conservan al cambiar de pestaña. Salir o cambiar de módulo exige cerrarlas desde el plano.

## Alcance y comprobación

No hay cambios de esquema, endpoints, asignación del planificador, catálogos de negocio ni UUID de operaciones. Se conserva la separación entre variantes PDA/tablet/demo.

La revisión cubre la cabecera real a 360, 800 y 1280 dp, su menú y acciones, la navegación con estibas abiertas y las pruebas existentes de captura, recuperación, impresión, catálogo y sincronización. La exportación/publicación mediante Expo y la validación OTA en equipos de planta requieren su proceso habitual de entrega; no se publican desde esta tarea.
