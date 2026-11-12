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
- **Fotos.** Set fotográfico estándar, en el orden en que se toman. Las fotos se guardan
  comprimidas y, al cerrar, pasan a un solo PDF (ver «Fotos en PDF»).

El set es el oficial del equipo de consolidación para despachos marítimos de fruta fresca
(correo del 12-11-2026). La PDA lo muestra por etapa, con lo que debe verse en cada foto.

**Pizarra identificatoria**, obligatoria en los registros: debe leerse el instructivo, el N° de
contenedor, la planta, la exportadora y la fecha de carga. La PDA muestra esos datos tomados del
encabezado (`pizarra` en el detalle) para copiarlos tal cual, y marca los que faltan. El PDF del
set los repite en el encabezado de cada página.

| Etapa | Categoría | Exigencia |
|---|---|---|
| 1. Exterior | Camión y patentes (tracto y rampla) | Obligatoria |
| | Contenedor (sigla y número) | Solo contenedor |
| | Sello de lavado con que llega la unidad | Solo contenedor |
| | Placa de información del contenedor (año y tara) | Solo contenedor |
| | Pantalla del equipo de frío con el seteo | Obligatoria |
| | Ventilación según instructivo | Solo contenedor |
| 2. Interior, carga y estiba | Unidad vacía por dentro | Obligatoria |
| | Temperatura de pulpa antes de cargar | Una por pallet con posición |
| | Primera carga al fondo con el primer termógrafo | Obligatoria |
| | Mitad de la carga | Obligatoria |
| | Cartón piso y bufandas | Solo contenedor |
| | Segundo termógrafo (penúltimo pallet) | Si el encabezado anota un segundo termógrafo |
| | Final de la carga y cierre (espiga o ninfa visible) | Obligatoria |
| 3. Cierre y arranque | Sello de cierre en primer plano | Obligatoria |
| | Vista trasera con el cierre completo | Obligatoria |
| | Pantalla al arrancar el equipo de frío | Obligatoria |
| Otras | Otras fotos del despacho | Opcional |

**Las obligatorias bloquean el cierre.** Para dejarlas solo como pendientes, se agrega
`CONTROL_DESPACHO_EXIGE_FOTOS=false` en el `.env` de planta. Las categorías se definen en
`FormularioControlDespacho::tiposFoto()`.

La migración `2026_11_12_120000_set_fotografico_consolidacion` pasa las fotos del set provisional
a la categoría equivalente: patente → camión y patentes, temperatura de corte → seteo del equipo,
primer bloque → mitad de la carga. Postura de termógrafos y altura de carga pasan a «Otras». Los
PDF ya armados conservan sus rótulos anteriores.

## Fotos en PDF

Un set va de 20 a 40 fotos según el cliente. Para no acumularlas sueltas, en el servidor queda
**un solo PDF por despacho**:

1. **Al subir**, cada foto queda derecha según su EXIF, en JPEG de hasta 1280 px por lado
   (calidad 60), con una miniatura para la pantalla.
   - El original del equipo no se guarda: una foto de varios MB queda en unos 100 a 200 KB.
   - En el PDF cada foto ocupa unos 18 × 8 cm, y a ese tamaño 1280 px conserva legible una
     patente o el display de un termógrafo.
   - La foto se comprime una sola vez: no pierde calidad si el PDF se rearma.
2. **Al cerrar la planilla**, las fotos se arman en el PDF «Set fotográfico R-24»:
   - tres por página, ordenadas por categoría (40 fotos quedan en 14 páginas);
   - con su nombre y la hora y el usuario que la tomó;
   - en `control-despacho/{control}/set-fotografico-….pdf`, en el disco privado.

   Luego se borran las fotos sueltas y sus miniaturas. Las filas de `fotos_control_despacho` se
   conservan como registro, con `archivada_at` y su página en el PDF.
3. **Si la planilla se reabre**, se pueden quitar fotos archivadas o agregar nuevas. Al volver
   a cerrar, el PDF se rearma: las fotos ya archivadas se leen desde el PDF anterior, que
   guarda cada JPEG intacto (`pdf_offset`, `pdf_longitud`). Después se borra el PDF anterior.

Si el armado del PDF fallara al cerrar, la planilla igual queda cerrada y el PDF se arma la
primera vez que se pida. Se ve en oficina («Ver PDF de fotos») y se comparte desde la PDA
(«Compartir PDF de fotos»): `GET /api/control-despacho/controles/{id}/fotos.pdf`.

## T° de pulpa: se exige al imprimir

La planilla R-24 exige la T° de pulpa de cada pallet, pero **no bloquea el cierre**: el camión
puede salir y la temperatura se completa después.

- Al **descargar o compartir el Excel**, si falta alguna T° de pulpa, el sistema lista los
  pallets y pide completarlas antes de imprimir. Responde 422 con
  `codigo: faltan_datos_impresion`.
- Se completan desde la PDA (pestaña Pallets, «T° de pulpa pendiente») o desde oficina, en el
  mismo aviso de descarga, si el usuario puede registrar la planilla.
- Con la planilla **cerrada** solo se completan las temperaturas que faltan. Las ya
  registradas no se cambian, y la copia congelada de la planilla se actualiza.

## Cerrar y reabrir

Para cerrar la planilla:

- se indica si es contenedor o thermo;
- la inspección está completa;
- no quedan pallets de la carga sin subir;
- cada pallet cargado tiene posición;
- las fotos obligatorias del set están tomadas (salvo `CONTROL_DESPACHO_EXIGE_FOTOS=false`).

La T° de pulpa no se exige para cerrar.

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
| POST | `/api/control-despacho/controles/{id}/temperaturas` (completa la T° de pulpa, también con la planilla cerrada) |
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
