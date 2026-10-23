# Unidades y equivalencias de Materiales — PR 3 del plan

En Materiales → Catálogos → Unidades se administran las unidades globales. El código es
permanente; se puede cambiar el nombre, desactivar para nuevas altas y agregar sinónimos.
Los sinónimos se conservan para seguir reconociendo datos anteriores. Cada cambio exige
motivo, controla la versión y registra responsable, fecha y valores anteriores/nuevos.
Al editar se ingresan solamente los sinónimos nuevos (hasta veinte por envío); los
anteriores permanecen en el listado y en el respaldo de auditoría.
Usa el permiso existente `administrar-catalogos-materiales`.

La migración vincula los ítems al catálogo, agrupando variantes como Unidad, unidades y
UN. No reescribe sus textos originales, fechas ni cantidades, ni cambia folios,
recepciones, etiquetas o versiones de recetas. Las unidades no reconocidas se incorporan
al catálogo. Las importaciones y clientes anteriores que envían texto siguen admitidos:
se reconoce el sinónimo o se da de alta una unidad nueva con auditoría.

El formulario de ítems usa un desplegable. Una unidad inactiva no puede asignarse a un
ítem nuevo; sus ítems existentes pueden continuar operando y sus documentos se conservan.
La unidad base no puede cambiar después de tener folios, recepciones (también borradores
o eliminadas), despachos, recetas o equivalencias. Seleccionar un sinónimo conserva la
representación histórica. Los cambios reales de unidad se rechazan explícitamente.

## Equivalencias por ítem

Se configura cuánto representa **una unidad de ingreso en unidades base**, con factor
positivo de hasta seis decimales. Ejemplo: para CAJA-PLANA, cuya base son unidades,
`1 caja = 100 unidades`. Otro ítem puede tener 120 unidades por caja. No se define una
equivalencia global entre cajas y unidades, ni se reutiliza el factor de una receta.

Al registrar una recepción se elige la unidad de ingreso. Todas las cantidades de esa
línea —documental, contada, aceptada, rechazada y cada bulto— se ingresan en esa unidad.
El servidor aplica el factor del ítem y verifica su versión. Guarda los valores en la
unidad base, con el respaldo de cantidades originales, unidad de ingreso, factor y versión.
Al confirmar se genera stock una sola vez y en la unidad base. Los reintentos conservan
la idempotencia existente. Cambiar la equivalencia no recalcula recepciones ya guardadas.

Las conversiones usan aritmética decimal exacta (`brick/math`, ya presente). Si el resultado
no cabe en tres decimales o supera el máximo del inventario, la operación se rechaza sin
redondear ni dejar un ingreso parcial. Si el factor cambia después de cargar el formulario,
se exige actualizar y revisar las cantidades. Un borrador ya convertido conserva su factor
al confirmarse y al corregir antecedentes sin cambiar cantidades. El servidor toma ese
respaldo del detalle original de la misma recepción; no acepta factores históricos del
cliente. Si se cambian cantidades, exige la equivalencia vigente. La opción «Usar
equivalencia vigente» permite aplicarla explícitamente y revisar las cantidades. Cada
bulto conserva su cantidad de ingreso por identificador, sin depender del orden del listado.

## Despliegue y comprobaciones

Aplicar las migraciones normales y compilar Oficina. No requiere una APK nueva. El cambio
parte de #491 (categorías/tipos, PR 2), con #489 ya integrado. El orden de integración
es #491 y después #490. Ambos selectores y sus permisos se conservan; la prueba de
categorías usa el desplegable de unidades. El alcance revisable de #490 corresponde a
las unidades y sus equivalencias, sin repetir la implementación de categorías/tipos.

Las pruebas cubren normalización, precisión, límites, permisos API, versiones, factores
por ítem, reintentos, conservación de cantidades históricas, confirmación de stock en
unidad base y migración sin cambios en los registros existentes. La prueba de navegador
cubre catálogo, desplegable y equivalencias en escritorio y celular.
