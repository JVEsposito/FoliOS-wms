# Diagramas de pallets SAG/USDA

En **Frigorífico → Diagramas SAG/USDA** se arma el diagrama de presentación de
pallets para la inspección: cada caja se dibuja en su posición y lleva un número
correlativo único entre todos los pallets. Reemplaza la planilla Excel con macro
(«DIAGRAMA USDA Nº …»), que solo admitía una configuración igual para todos los
pallets y permitía saltos al editar a mano.

## Numeración

- La caja 1 es la primera de la corrida inferior del pallet 1.
- Cada corrida se numera de izquierda a derecha, de abajo hacia arriba.
- El pallet siguiente parte en el número posterior a la última caja del anterior.
  Nunca se reinicia.
- Si cambia la base, las corridas o la última corrida de un pallet, se recalculan
  todos los posteriores.
- La última corrida puede quedar incompleta: se indica cuántas cajas tiene y se
  dibuja arriba, con sus cajas desde la izquierda.

La regla vive en dos lugares que se prueban con los mismos casos:
`resources/js/shared/sag-pallet-diagram.js` (pantalla y PNG) y
`App\Services\InspeccionSag\NumeracionDiagramaPallets` (guardado y PDF). El
servidor vuelve a calcular la numeración y rechaza lo que no cuadra.

## Pallets

- Un diagrama nuevo parte con 16 pallets **sin configurar**: no se suponen bases ni
  corridas. «Aplicar a todos» o «Aplicar a los sin configurar» copian una misma
  configuración, y cada pallet se puede cambiar después.
- Cada pallet admite un folio opcional y unas «cajas declaradas» opcionales: si se
  indican y no coinciden con la base y las corridas, el diagrama no se guarda ni se
  emite.
- Se pueden agregar pallets (sin configurar) o quitarlos. Límites: 80 pallets, base
  de 1 a 50 cajas y de 1 a 50 corridas.

## Cajas marcadas

- Se escriben uno o varios números («5, 82, 145, 206 y 411»; también rangos 10-15)
  o se marca y desmarca con un clic sobre la caja. Se muestran en amarillo.
- Un número que no existe en el diagrama no se marca y se informa.
- Las marcas son números de caja. Si se cambia la estructura de los pallets, la
  pantalla advierte que su posición puede haber cambiado; si una marca queda fuera
  del total, se avisa y se puede quitar. No se guarda ni se emite con marcas
  inexistentes.
- El resumen indica, por pallet, cada caja marcada con su corrida (desde abajo) y
  su posición (desde la izquierda).
- **Leer captura** carga una imagen PNG o JPEG con la lista de números. La lectura
  agrega los números al campo editable. Se aplican al diagrama al pulsar **Marcar**.
  En el servidor, usa Tesseract con los datos de idioma inglés y configura
  `SAG_TESSERACT_BINARY` si el ejecutable tiene otra ruta.

## Leer presample

**Leer presample** recibe el «Certificado de Pre-Muestreo USDA/SAG» escaneado. Puede ser un PDF de una página o una foto PNG o JPEG, de hasta 10 MB.

- **Claude** lee el documento con la clave y el modelo del asistente. Devuelve el N.º de lote, el rango «LOTE DE … A …», los tamaños de partida y de muestra, el n.º de pallets y la tabla de cajas por columna.
- **Tesseract** lee la misma página. Una caja que también encuentra Tesseract queda *confirmada*; si no, queda *por revisar* y se muestra en amarillo. Con el escaneo real del 25-11-2026 confirmó 63 de 69 cajas.
- **El propio documento se usa de control.** No se puede aplicar si la cantidad de cajas no es igual a la muestra, si las cajas leídas por columna no van en orden creciente o se repiten, si alguna pasa del tamaño de la partida, o si los rangos no suman el n.º de pallets.
- **Aplicar:**
  - El N.º de lote va al **N.º del diagrama** y al **Lote**, porque en la planta es también el número de presample.
  - El rango va al **Folio**.
  - Solo se completan campos vacíos. Si ya tienen otro valor, se avisa y no se cambian.
  - Las cajas se agregan al campo de marcas y se marcan al pulsar **Marcar**.
- **Costo.** Cada lectura cuenta en el tope mensual del asistente y queda en el registro de llamadas con la herramienta `leer_presample`. No cuenta en el límite diario de preguntas y no exige el piloto. Se permiten 6 lecturas por minuto.
- **Sin clave, sin saldo o con falla de la API**, la revisión muestra solo la lectura de Tesseract: todas las cajas por revisar, sin lote ni rango.
- **En el servidor.** Para leer PDF, Tesseract necesita `pdftoppm` (paquete `poppler-utils`). Si el ejecutable está en otra ruta, configura `SAG_PDFTOPPM_BINARY`. El archivo no se guarda.

## Salidas

- **PDF** en A4 o A3 horizontal, generado en el servidor con lo que está en
  pantalla, guardado o no. La celda se ajusta para usar las menos hojas posibles
  sin bajar los números de 6 pt; la última hoja es el resumen.
- **PNG** de alta resolución, generado en el navegador.
- **Imprimir** abre el mismo PDF con el papel elegido.
- Mostrar u ocultar los números aplica a la pantalla y a las tres salidas.
- **Adhesivos de muestreo** emite una etiqueta por caja marcada con la exportadora
  y el pallet. Hay hoja A4 para recortar y rollo de 100 × 50 mm. Imprime al 100 %.

Los documentos son neutrales: no llevan nombre ni logo de la planta. El CSP es un
campo del diagrama; un diagrama nuevo lo toma de la configuración de Contraparte
SAG y se puede cambiar. Todas las salidas indican que el diagrama es de uso interno
y **no constituye aprobación del SAG ni del USDA**.

## Guardado y permisos

- Los diagramas pertenecen a la temporada activa y se identifican por inspección
  (USDA o SAG) y N.º: no se repite el mismo N.º de la misma inspección en una
  temporada.
- Guardar usa `operacion_id` con hash del contenido (un reintento no duplica) y la
  versión del diagrama (si otra persona guardó antes, se pide volver a abrirlo).
  Cada versión queda en `eventos_diagrama_pallets_sag`.
- Consultar, abrir y emitir requieren el permiso de consulta de Inspección SAG;
  guardar, el de gestión. No hay un módulo de acceso nuevo.
- El reinicio operacional de una temporada borra sus diagramas.
- El diagrama no mueve pallets, no cambia cajas ni aprueba nada.
