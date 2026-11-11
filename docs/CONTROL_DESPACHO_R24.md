# Control de despacho R-24 (Consolidación)

Planilla **POEMP R-24 «Registro planilla control de despacho área frío»**. El equipo de
consolidación la llena desde la **PDA**, módulo **Consolidación**. Oficina la consulta y la
descarga en **Frigorífico → Despacho → Control de despacho (R-24)**
(`/oficina/frigorifico/control-despacho`).

Hay una planilla por carga. Lo que FoliOS ya sabe llega sugerido; el equipo lo confirma o
corrige.

## Cuándo se suma consolidación

La planilla se abre cuando la carga está **en andén**: el camión registró su llegada o al menos
un pallet ya salió de la cámara. Antes de eso la carga no aparece en la PDA.

**El carguío del camarero no cambia.** Las cargas que no necesitan consolidación se despachan
como siempre.

## Pallets: «subió» y posición

En la pestaña **Pallets** se lee el folio y se registra su posición en el camión o
contenedor (1 a 60). También P/E, temperatura de pulpa y cajas.

**P/E** es la orientación del pallet respecto del frente del camión o contenedor. En un pallet
1000 × 1200:

- **P · Punta:** lado angosto hacia el frente.
- **E · Espejo:** lado ancho hacia el frente.

La PDA mantiene la orientación elegida para el pallet siguiente, porque en una carga suelen ir
todos igual. En la planilla se escribe la letra.

| Estado del pallet | Qué hace la planilla |
|---|---|
| En andén | Lo deja **cargado** con la misma transición que usa el camarero (evento `folio_cargado`, marcado `registrado_desde: consolidacion`) y guarda su posición |
| Ya cargado por el camarero | Solo guarda o corrige su posición |
| En cámara, o de otra carga | Rechaza la lectura y dice por qué |

- Si dos pallets comparten una posición, la PDA pide confirmarlo.
- Quitar un pallet de la planilla **no** lo baja del camión. Para eso, el camarero usa
  «Bajar» con su motivo.
- Las cajas se toman del folio y se pueden corregir.

## Lo que llega sugerido

| Campo R-24 | Origen en FoliOS |
|---|---|
| Frigorífico | Agrorosario |
| Tipo de operación | Modalidad del embarque (marítimo, aéreo, terrestre) |
| Tipo de camión | Contenedor, si el embarque tiene número de contenedor |
| Exportadora | Cliente del embarque, o la exportadora común de los folios |
| N° referencia embarque | N° externo del instructivo, orden externa o código del embarque |
| Fecha / hora instructivo | Fecha y hora programadas del embarque |
| Fecha / hora de carga real | Primer y último pallet registrado como cargado |
| N° guía de despacho | Guía ingresada al cerrar el despacho |
| Recibidor, destino y puerto de destino | Instructivos del embarque, si son comunes |
| Nave y puerto de zarpe | Embarque |
| Especie | Folios de la carga |
| Empresa de transporte | Transportista del embarque |
| Conductor y patente | Camión en andén, o lo indicado en el cierre del despacho o en el embarque |
| Contenedor y sello naviera | Embarque |
| Cajas totales guía | Suma de cajas de los folios |

Un campo que se deja vacío vuelve a la sugerencia.

## Inspección, cierre y fotos

- **Inspección visual.** Tiene 13 puntos, y cada uno se responde Sí, No o No aplica. Los
  puntos del otro tipo de camión se marcan «no aplica» al guardar, por ejemplo el horómetro
  en un contenedor.
- **Cierre.** Registra:
  - cajas de la guía;
  - sello de lavado;
  - observaciones;
  - etiquetado (Corea, China, Canadá / Corea, Brasil, México / cliente);
  - requerimiento Walmart;
  - cantidad por tipo de pallet;
  - responsable y jefe de sección.
- **Fotos.** Son opcionales, hasta 20, y se guardan en el disco privado. Cuando llegue el
  set fotográfico estándar se definen sus categorías en
  `FormularioControlDespacho::tiposFoto()`, con `obligatoria: true` si corresponde. La PDA y
  oficina las muestran sin otros cambios.

## Cerrar y reabrir

Para cerrar la planilla:

- se indica si es contenedor o thermo;
- la inspección está completa;
- no quedan pallets de la carga sin subir;
- cada pallet cargado tiene posición.

Al cerrar, las sugerencias quedan confirmadas y la planilla se congela (`planilla_cerrada`).
Si no se indicó responsable, queda a nombre de quien la cerró.

Solo **supervisión de frío o administración** reabre una planilla cerrada, con un motivo
(mínimo 10 caracteres). La reapertura queda en su historial.

## Excel R-24

**Descargar R-24 (Excel)** en oficina, o **Compartir Excel R-24** en la PDA, llena la plantilla
oficial `resources/templates/control-despacho/poemp-r-24.xlsx`.

- Las posiciones impares van a la izquierda y las pares a la derecha.
- Cada hoja trae 22 posiciones. Un camión con más posiciones sigue en otra hoja, con el mismo
  encabezado.
- El pie dice si la planilla está cerrada, cuándo y por quién, o si sigue en borrador.

## Accesos

| Módulo | Uso |
|---|---|
| `frigorifico.control-despacho` (oficina) | Consultar y descargar |
| `consolidacion` (PDA) | Registrar la planilla |

La migración crea el perfil **Consolidación de despacho**, con rol base despachador y ambos
módulos. También agrega los dos módulos a los perfiles predeterminados de administración y
supervisión de frío.

Capacidades:

- `puede_consultar_control_despacho`
- `puede_registrar_control_despacho`
- `puede_reabrir_control_despacho`

## API

| Método | Ruta |
|---|---|
| GET | `/api/control-despacho/catalogo` · `/cargas` · `/controles` · `/controles/{id}` · `/controles/{id}/planilla.xlsx` |
| POST | `/api/control-despacho/cargas/{carga}/control` (abre o devuelve la planilla) |
| PUT | `/api/control-despacho/controles/{id}` (secciones `encabezado`, `inspeccion`, `cierre`, con `version`) |
| POST / DELETE | `/api/control-despacho/controles/{id}/posiciones` · `/posiciones/{posicion}` |
| POST | `/api/control-despacho/controles/{id}/cerrar` · `/reabrir` |
| GET / POST / DELETE | `/api/control-despacho/controles/{id}/fotos` |

Todas las escrituras son idempotentes por `operacion_id`, porque pasan por el motor de
transiciones (dominio `despacho`). Guardar y cerrar exigen la `version` vigente: si otro
equipo guardó antes, la respuesta es 409.

## Pruebas

- `tests/Feature/Api/ControlDespachoApiTest.php`
- `tests/Browser/OfficeDispatchControlTest.mjs`
- `tests/JavaScript/DispatchControlTest.mjs`
- `mobile/tests/consolidation.test.mjs`
- `mobile/tests/consolidationScreen.test.mjs`
