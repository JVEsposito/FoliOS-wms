# PR 12 · Tablet: portada «Mi jornada» enfocada

**Rama:** `claude/tablet-portada-jornada` · **Base:** `main` · **Entrega:** implementación
**Título del PR:** `feat(tablet): portada Mi jornada enfocada en una maniobra`
**Archivos:** `mobile/src/components/operator/OperatorTaskHome.tsx`, `mobile/src/components/operator/OperatorToast.tsx` (nuevo), `mobile/src/components/OperationalTaskInbox.tsx`

## Problema

En la portada, el folio, que es el dato que el camarero coteja con la etiqueta, quedaba bajo el
borde de la pantalla. Antes de él se apilaban:

- la hora de actualización;
- la franja verde del último movimiento;
- el botón ancho de cargas;
- el título «Mi jornada operacional» con el botón Actualizar;
- la cabecera de la tarjeta con su botón.

Además, el estado «DISPONIBLE» se repetía dos veces y la instrucción repetía el origen y el
destino.

## Cambio

Dos columnas en horizontal (≥ 800 dp; bajo eso se apilan):

**Maniobra (columna principal)**
- Rótulo «SIGUIENTE MANIOBRA» con los badges de prioridad y objetivos. El badge de estado solo
  aparece cuando cambia lo que se puede hacer: en espera de supervisión, alternativa o en
  ejecución.
- El propósito como título y la meta en una línea.
- El **folio a 72 dp** en monoespaciada, con los primeros dígitos atenuados y los
  **últimos cuatro destacados**.
- Bloques **DESDE → HACIA**. Un destino pendiente se dibuja con borde punteado y el texto real
  de `operationalTaskDestinationLabel`. Una sugerencia del planificador lleva el rótulo
  «PROPUESTO».
- Paso y compromiso en una línea discreta, solo si aportan algo (por ejemplo «Paso 2 de 3 ·
  Posición reservada»).
- Una sola acción de **84 dp** con el texto y la habilitación actuales (`primaryActionLabel`,
  `disabled={busy || alternative}`).

**Columna lateral (340 dp)**
- La hora de actualización y un botón ↻. En la portada también se puede deslizar hacia abajo
  para actualizar.
- Las labores del turno (`OperatorShiftDuties`, sin cambios).
- **Luego**: las pestañas «Mis tareas» y «Disponibles», las primeras 3 maniobras y la opción
  «Ver N más».
- **Último movimiento**: folio, destino y hora del último movimiento completado en esta sesión.
- **Cargas del turno**: aparece si la persona tiene `puede_consultar_cargas`.

**Avisos informativos (nivel 1)**
- La franja verde `noticeBanner` se reemplaza por `OperatorToast`.
- En la portada aparece abajo a la derecha, sobre la columna lateral, y nunca tapa la maniobra.
- En las demás vistas (maniobra en ejecución, cargas, confirmación pendiente) va dentro del
  flujo de la pantalla, para no cubrir sus botones.
- Se cierra solo entre 5 y 12 s según el largo del texto, o al tocarlo.

**Fuera de la portada**
- Se mantiene una línea discreta con la hora de actualización.
- Durante una maniobra sigue disponible «Cargas activas del turno →», sin perder el movimiento
  en curso.
- Un problema de conexión se muestra como franja ámbar en cualquier vista.

## Qué no cambia

- El orden de la cola (`buildOperatorTaskHome`), las acciones (tomar, iniciar, continuar, ver
  estado) y sus condiciones.
- Los textos y la recuperación de confirmaciones pendientes.
- El panel `OperatorTaskPurpose`, que sigue en la maniobra en ejecución. En la portada solo se
  muestran el título y la meta.

## Pruebas

- Nueva: `tests/JavaScript/OperatorTaskHomeFocusTest.mjs`.
- Siguen verdes `OperatorTaskHomeTest`, `OperatorTabletVisualTest` y `BrandIdentityTest`.
- `mobile/tests/operationalTaskErrors.test.mjs` y `operationalTaskReliability.test.mjs` reciben
  `setTimeout` y `clearTimeout` simulados, igual que ya tenían `setInterval`, porque el aviso
  usa un temporizador.
