# Unidades y equivalencias de Materiales — PR 3 del plan

En Materiales → Catálogos → Unidades se administran las unidades globales. El código es
permanente; se puede cambiar el nombre, desactivar para nuevas altas y agregar sinónimos.
Los sinónimos se conservan para seguir reconociendo datos anteriores. Cada cambio exige
motivo, controla la versión y registra responsable, fecha y valores anteriores/nuevos.
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
al confirmarse; para editarlo con un factor nuevo se debe volver a seleccionar la unidad
de ingreso y revisar las cantidades.

## Despliegue y comprobaciones

Aplicar las migraciones normales y compilar Oficina. No requiere una APK nueva. El cambio
parte de `main` con #489 integrado; no implementa categorías/tipos del PR 2. Si ese PR
modifica los mismos formularios, integrar sus cambios y volver a comprobar ambos selectores.

Las pruebas cubren normalización, precisión, límites, permisos API, versiones, factores
por ítem, reintentos, conservación de cantidades históricas, confirmación de stock en
unidad base y migración sin cambios en los registros existentes. La prueba de navegador
cubre catálogo, desplegable y equivalencias en escritorio y celular.
