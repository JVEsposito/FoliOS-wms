# Etiqueta Clásica bodega de Materiales

Diseño opcional para recepciones confirmadas, sobre papel de 200 × 100 mm. El selector
abre en **Actual** y conserva los modelos de recepción, armado y transferencia existentes.

## Datos impresos

| Campo | Fuente |
| --- | --- |
| Logo | Agrorosario, versión monocroma del recurso existente |
| Nombre destacado | Cliente dueño de la recepción |
| Fecha | Fecha física de recepción, convertida a la zona operacional de Chile |
| Guía | Número de guía de la recepción; `PENDIENTE` si aún no está informado |
| Proveedor | Proveedor de la recepción |
| Código y descripción | Ítem correspondiente al bulto |
| Cantidad y unidad | Cantidad recibida de ese bulto, con hasta tres decimales |
| Folio y Code 128 | Folio completo del bulto/pallet, sin renumeración ni espacios añadidos |

La cantidad **no es el total de la guía ni el saldo disponible después de despachos**.
Por ejemplo, un bulto recibido con 1.500 UN sigue indicando `CANTIDAD RECIBIDA 1.500 UN`
aunque su saldo actual sea menor. Para etiquetar otra unidad física se utiliza su propio folio.

En recepciones históricas sin fecha física se toma la fecha de ingreso del folio y,
si tampoco existe, la confirmación. No se utiliza la fecha de creación del borrador.
Un folio actualmente bloqueado muestra **BLOQUEADO**; el motivo completo queda en el
snapshot del trabajo aunque se abrevie en papel.

## Operación

1. En Oficina → Materiales → Etiquetas, seleccionar una recepción confirmada.
2. Seleccionar un perfil de 100 × 200 o 200 × 100 mm y los bultos.
3. Elegir **Clásica bodega · cantidad destacada** y el archivo PDF o NiceLabel.
4. En tablet/PDA, el mismo selector está en el panel de impresión de la recepción.
   La impresión IP usa ZPL y el perfil configurado en ese equipo.

El diseño clásico utiliza **Code 128**. Las opciones QR y los otros tamaños siguen
disponibles en **Actual**. Clásica no se ofrece para órdenes de armado o transferencias.
Si se cambia a un tamaño incompatible, Oficina vuelve a Actual y muestra la condición;
la tablet comprueba el perfil antes de enviar.

PDF y NLBL muestran la cara apaisada de 200 × 100 mm. En ZPL se respetan el DPI y las
dimensiones del perfil: **para Bixolon SLP-TX400 y Zebra ZT231 con rollo de 100 mm,
utilizar un perfil de 100 × 200 mm vertical**. Los textos, logo y código se giran juntos;
el trabajo no solicita un ancho de cabezal de 200 mm con ese perfil. En NiceLabel /
ZebraDesigner revisar orientación y papel en las propiedades del documento/impresora
antes de la primera impresión. El archivo editable es apaisado.

## Trazabilidad y compatibilidad

Se reutiliza `imprimir-etiquetas-materiales`, sin nuevos privilegios. Cada trabajo conserva
el diseño y su versión en `perfil_snapshot`, y los datos impresos en `contenido_snapshot`.
El diseño forma parte del hash de la operación cuando es Clásica. Omitir `diseno` o enviar
`actual` conserva los hashes anteriores; clientes antiguos y trabajos históricos siguen
utilizando su comportamiento original.

El servidor anuncia los diseños disponibles por perfil. Una OTA nueva conectada a un
servidor antiguo mantiene Clásica deshabilitada, para evitar que ese servidor ignore el
campo y genere otro modelo sin avisar.

Un UUID repetido devuelve el mismo snapshot, incluso si luego cambia el catálogo, la fecha
o el perfil. Cambiar de diseño requiere una operación nueva y, si el folio ya fue generado,
el motivo de reimpresión habitual. La generación no modifica saldos, ubicaciones ni kardex.
Un fallo al generar el archivo clásico revierte el trabajo: no consume una primera impresión.

Los textos largos se ajustan, la descripción puede ocupar dos líneas y los datos que no
caben legiblemente se rechazan con una explicación para utilizar Actual. El logo monocromo
está incorporado en código para no exigir GD, archivos externos ni acceso a Internet al imprimir.

## Despliegue y aceptación

Actualizar el backend y compilar Oficina (`npm ci` y `npm run build`). Este cambio no añade
migraciones ni dependencias. Para seleccionar Clásica desde los dispositivos se requiere
OTA de los canales utilizados: `production`, `pda` y `camaras-pruebas`. No añade código
nativo ni cambia el runtime; la impresión IP necesita la APK que ya incluya ese módulo.

La verificación automatizada cubre cantidades por bulto, fecha física y zona horaria,
compatibilidad de UUID, motivos, snapshots, rechazo de tamaños y cambios de diseño en
Oficina y tablet/PDA. Se revisan PDF renderizado y ZPL simulado, y se decodifica el Code 128
completo. La simulación no sustituye una impresión física.

Aceptación en planta: abrir el NLBL en ZebraDesigner/NiceLabel, imprimir un bulto desde
Oficina y otro por IP, medir papel y márgenes, escanear ambos folios, comprobar cantidad/unidad
y fecha, y volver a Actual. También probar una descripción larga, un decimal y un bloqueado.
La apertura del nuevo NLBL y su orientación en el driver deben verificarse en Windows;
el entorno de desarrollo no ejecuta esas aplicaciones ni tiene acceso a las impresoras.
