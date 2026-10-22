# Proveedores y categorías de Materiales

## Regla operacional

La habilitación de abastecimiento se define por la combinación:

```text
proveedor + cliente + categoría de catálogo
```

El proveedor no se relaciona manualmente con cada ítem. Al habilitar una categoría para un cliente, quedan seleccionables todos los ítems activos de esa categoría dentro de la temporada operacional vigente.

Ejemplo:

```text
Corupac + AG-001 La Aguada + ABSORPAD
```

permite recibir todos los ítems activos de `ABSORPAD` pertenecientes a La Aguada, pero no habilita automáticamente esa categoría para otro cliente asociado al mismo proveedor.

## Configuración en oficina

Cada proveedor recibe un **código interno automático** (`PRV-001`, `PRV-002`, …).
Es global, continúa al superar 999, no se reinicia por temporada y no se edita.
La asignación se serializa en una transacción y tiene una restricción única en la base.

El campo **Código comercial / tarja** es opcional y conserva el código que utiliza
el negocio, por ejemplo `CSF`. Si queda vacío, se utiliza el código interno. Los
proveedores históricos conservan su código comercial, UUID, asociaciones y fechas;
la migración únicamente les asigna el nuevo correlativo. Los códigos comerciales
históricos equivalentes no se fusionan automáticamente.

Al crear o cambiar un código comercial, se rechaza otro proveedor que ya lo use,
incluidos los inactivos, aunque se omitan guiones, puntos o guiones bajos. También
se protegen los códigos internos frente a su uso como código de otro proveedor.
La asignación salta números que ya identificaban a un proveedor histórico.

Encima del listado hay búsqueda por nombre, código interno, código comercial o
cliente, y filtros de cliente asociado y estado. Las palabras pueden combinarse
y la búsqueda de oficina no distingue tildes ni mayúsculas. Los filtros no borran
el formulario que se está preparando. El contador muestra visibles y total.

La API de administración conserva la respuesta `data` y admite `q`, `cliente_id`
y `estado` (`todos`, `activo`, `inactivo`). `codigo` mantiene su significado
comercial para que las recepciones, tarjas y aplicaciones existentes continúen
funcionando; `codigo_interno` es de solo lectura. Al actualizar, omitir `codigo`
conserva el valor anterior y enviarlo vacío vuelve al código interno.

En **Oficina de Materiales → Proveedores**:

1. Se registra o edita el proveedor.
2. Se seleccionan los clientes asociados.
3. Para cada cliente se selecciona al menos una categoría disponible en el catálogo de la temporada elegida.
4. La interfaz informa cuántos ítems activos habilita cada categoría.

Un cliente no puede mantenerse asociado sin categorías habilitadas.

## Recepción en tablet

El flujo de selección es:

```text
cliente → proveedor autorizado → ítems de categorías habilitadas
```

Cambiar el cliente o proveedor limpia los ítems previamente seleccionados para evitar combinaciones obsoletas.

## Validación de seguridad

La interfaz filtra las opciones, pero Laravel valida nuevamente la relación al crear el borrador y al confirmar la recepción. Una solicitud que intente usar un ítem cuya categoría no está habilitada para ese proveedor y cliente es rechazada.

## Compatibilidad

La migración inicial asigna a cada vínculo activo existente todas las categorías activas que actualmente posee su cliente. Esto evita bloquear recepciones ya configuradas y permite que la restricción se refine posteriormente desde Oficina de Materiales.

`categoria` es la clasificación comercial del catálogo, como `ABSORPAD`, `CAJAS` o `ETIQUETAS`. No reemplaza a `categoria_operacional`, que continúa distinguiendo `insumo`, `material_mp` y `material_pt`.

## Cobertura automatizada

Las pruebas verifican la creación de proveedores con categorías por cliente, la publicación del catálogo para tablet, el rechazo de ítems no autorizados, la compatibilidad con Transformación de Materiales y la presencia de los controles correspondientes en oficina y móvil.


## Clasificación operacional de los ítems

La categoría comercial y el tipo de ítem cumplen funciones diferentes:

- `categoria`: familia comercial utilizada para habilitar proveedores, por ejemplo `ABSORPAD`, `CAJAS` o `ETIQUETAS`;
- `categoria_operacional`: comportamiento logístico del ítem: `insumo`, `material_mp` o `material_pt`.

Una categoría comercial puede asociarse al proveedor aunque algunos de sus ítems todavía estén pendientes de tipificación. La oficina muestra cuántos están tipificados y cuántos pendientes. Sin embargo, un ítem sin tipo operacional no se ofrece en Recepción ni puede participar en una receta.

Los ítems existentes pueden editarse para agregar el tipo. La carga masiva acepta la columna `tipo_item` y los valores `insumo`, `material_mp` y `material_pt`.
