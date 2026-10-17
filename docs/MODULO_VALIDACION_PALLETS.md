# Módulo de Validación de pallets/PT

## Objetivo

Validación PT registra el nacimiento trazable de pallets y saldos de producto terminado antes de Prefrío.

```text
captura del pallet
→ Validación PT
→ folio pendiente de Prefrío
→ proceso térmico
→ habilitación para almacenamiento
→ ubicación en cámara
→ disponibilidad para carga
```

Una aprobación crea el folio de manera atómica, pero no lo ubica ni lo deja disponible para cargas. El estado inicial es `pendiente_prefrio`.

Validación PT es diferente de Validación MP. Validación MP opera recepciones de materia prima `REC-*`; Validación PT crea folios de producto terminado.

## Temporada y clientes

La captura utiliza la temporada global activa. Accesos es el único módulo que puede crear, editar, activar o migrar temporadas.

Los clientes son maestros globales. Validación mantiene por temporada:

- relación del cliente con el catálogo;
- marcas;
- categorías;
- especies;
- variedades;
- calibres;
- envases;
- CSG y variedades autorizadas.

Los cambios del maestro global no reescriben snapshots históricos de validaciones existentes.

## Catálogo jerárquico

La oficina `/oficina/validacion/catalogo` administra:

```text
Temporada
├─ clientes globales habilitados
│  └─ marcas estacionales
├─ categorías independientes
├─ especies
│  ├─ variedades
│  ├─ calibres
│  └─ envases
└─ CSG
   └─ variedades autorizadas
```

Una proyección transaccional mantiene el contrato compuesto consumido por la PDA:

- artículo = especie + variedad + calibre + envase;
- origen = cliente + marca + CSG;
- combinación = artículo + origen autorizado;
- categoría = selección independiente dentro de la temporada.

Los registros proyectados antiguos no se eliminan cuando dejan de estar autorizados; quedan inactivos para preservar referencias históricas.

## Compatibilidad de catálogo

La proyección actual considera:

- todas las combinaciones activas de variedad, calibre y envase dentro de una especie;
- todas las relaciones comerciales proyectadas entre marcas y CSG de la temporada;
- autorización de la combinación únicamente cuando el CSG admite la variedad;
- categoría activa independiente del artículo y origen.

Antes de publicar un catálogo productivo, operación debe confirmar que estas relaciones representan la realidad comercial. Restricciones adicionales por cliente, marca, categoría, envase o calibre deben modelarse explícitamente; no deben inferirse desde nombres.

Si una temporada heredada conserva clientes o asociaciones CSG anteriores a la
proyección automática, el administrador puede reparar el contrato operativo:

```bash
php artisan validacion:reparar-catalogo
```

El comando adopta las proyecciones heredadas de los clientes globales,
reconstruye artículos, orígenes y combinaciones de la temporada activa,
incrementa su versión y muestra los conteos anteriores y resultantes.

## Importación CSV/XLSX

La oficina `/oficina/validacion` permite:

```text
subir planilla
→ interpretar y validar
→ previsualizar altas, cambios y errores
→ confirmar transaccionalmente
→ reconstruir la proyección para PDA
```

Reglas:

- una planilla con errores no aplica filas parciales;
- una planilla vacía no genera una importación confirmable;
- el archivo puede incluir categoría;
- los registros ausentes no se desactivan automáticamente;
- confirmar dos veces no duplica la aplicación;
- el resultado conserva archivo, checksum, usuario, filas y errores interpretados;
- XLSX requiere la extensión PHP `zip`.

El archivo original no se conserva dentro del WMS; se guarda su checksum y el contenido interpretado necesario para auditoría.

## Captura móvil

La aplicación móvil funciona en orientación vertical y permite:

- descargar el catálogo activo;
- escanear o escribir el folio;
- seleccionar pallet o saldo;
- registrar cantidad de cajas;
- seleccionar categoría;
- seleccionar artículo y origen compatibles;
- aprobar, observar o rechazar según permisos;
- consultar capturas recientes;
- conservar el último catálogo conocido;
- guardar operaciones en una bandeja local.

La descarga se ejecuta al entrar y cada vez que la aplicación vuelve al primer
plano. El botón visible en el encabezado permite repetirla manualmente y confirma
la versión descargada. Android reserva 64 MB para AsyncStorage, y al detectar un
almacenamiento lleno reemplaza primero el catálogo anterior sin eliminar la
bandeja de operaciones.

La bandeja se separa por usuario y dispositivo. Cada captura se persiste antes de transmitirse y conserva el mismo UUID durante los reintentos.

Los envíos con error, incluso de sesiones anteriores, muestran el mensaje y permiten
**Retomar**. La PDA consulta el estado del UUID antes de abrir la corrección: si el
envío ya creó una validación, concilia la bandeja con el servidor; si fue rechazado
o falló, recupera la captura completa. Una decisión final registrada por otro envío
requiere revisión de supervisión y no se sustituye desde este flujo.

La captura original permanece guardada al abrir, cancelar o reiniciar el editor.
Al registrar la corrección se reemplaza en una sola escritura por un envío con UUID
nuevo, antes de transmitirlo. Si la respuesta se pierde, los reintentos de ese
envío conservan su UUID y contenido. Una transición rechazada nunca se reabre ni
se modifica para forzar un reintento.

## Intentos e idempotencia

Cada envío utiliza `operacion_id` UUID y hash del payload.

- Repetir el mismo UUID con el mismo contenido devuelve la validación existente.
- Reutilizarlo con contenido diferente genera conflicto.
- Cada folio conserva intentos numerados.
- Observar o rechazar no crea inventario.
- Una aprobación posterior a una observación crea un nuevo intento.
- Una decisión terminal no se sustituye silenciosamente.
- Una captura basada en un catálogo anterior conserva el snapshot y marca la diferencia de versión.

La secuencia de intentos se serializa por número de folio, permitiendo que distintas PDA validen folios diferentes en paralelo.

## Resultados

### Aprobado

- crea el folio si todavía no existe;
- registra la validación como origen;
- guarda temporada y datos externos;
- establece `estado_operacional = pendiente_prefrio`;
- establece `condicion_termica = pendiente_prefrio`;
- establece `habilitacion_almacenamiento = no_habilitado`.

### Observado

- no crea folio;
- conserva motivo, observación y snapshot;
- permite una captura posterior corregida.

### Rechazado

- no crea folio;
- exige motivo;
- constituye una decisión terminal según las capacidades y confirmación definidas.

## Paso a Prefrío

El folio aprobado aparece como candidato para Prefrío. Durante el proceso térmico cambia su condición y, al aprobarse, queda habilitado para almacenamiento.

La aprobación de Prefrío no crea la ubicación. Cámaras consulta el folio, recupera los datos nacidos en Validación y permite la primera ubicación cuando:

```text
estado_operacional = pendiente_prefrio
condicion_termica = prefrio_aprobado
habilitacion_almacenamiento = habilitado
```

Después de esa ubicación el folio pasa a `disponible`.

## Registro RRPP-01

La oficina permite descargar el registro completado según fecha, encargado,
línea y turno, o una copia en blanco independiente de la existencia de
validaciones. Ambas variantes se construyen desde la misma plantilla controlada
y conservan código, versión, columnas, total y configuración de impresión.

```http
GET /api/validacion/registro/rrpp-01
GET /api/validacion/registro/rrpp-01/en-blanco
```

## Roles

### `validador`

Puede:

- descargar catálogos;
- capturar aprobaciones y observaciones;
- consultar validaciones;
- operar la bandeja móvil.

No opera Cámaras, Cargas, Materiales, Romana ni Validación MP.

### `supervisor_frio`

Puede consultar y ejecutar las acciones de validación habilitadas, además de confirmar decisiones terminales según la política del módulo.

### `administrador`

Conserva acceso total y administra el catálogo desde oficina.

## API

### Captura y consulta

```http
GET  /api/validacion/catalogos
GET  /api/validacion/pallets
GET  /api/validacion/pallets/{id}
POST /api/validacion/pallets
```

### Corrección administrativa

```http
GET /api/validacion/pallets/{id}/correccion
PUT /api/validacion/pallets/{id}/corregir
```

Requieren `corregir-validaciones-pallet` y solo aplican a validaciones aprobadas que
conservan su folio. `GET …/correccion` entrega el bulto con su composición por CSG y el
catálogo activo de la temporada de esa validación, con la misma forma que usa la PDA.

`PUT …/corregir` acepta `composicion` (CSG, cajas, lote MP y proceso por línea). Con ella
reemplaza todas las líneas con las mismas reglas que la PDA:

- todos los CSG del catálogo activo, de un mismo cliente y marca;
- el artículo habilitado y la variedad autorizada en cada CSG;
- la suma de las líneas igual a `cantidad_cajas`;
- sin repetir CSG + lote + proceso;
- lote y proceso obligatorios cuando `WMS_VALIDACION_EXIGE_LOTE_PROCESO=true`.

El origen principal es el CSG de la primera línea y la fecha de embalaje se conserva. Sin
`composicion`, sigue funcionando la corrección de un solo CSG (`origen_validacion_id`).

En Oficina (Validación y Anulaciones) el diálogo sigue el orden de la PDA: bulto, cliente y
marca, líneas por CSG (se agregan o quitan; las cajas del bulto son la suma), producto
habilitado en esos CSG (especie → variedad → calibre → embalaje) y categoría. Cada lista se
filtra escribiendo: el CSG también por predio y el embalaje también por su código. Antes de
guardar muestra qué cambia frente a lo validado.

### Administración

Las rutas bajo `/api/administracion/validacion/*` permiten consultar el catálogo, mantener sus entidades, importar archivos y confirmar importaciones. Todas requieren la capacidad administrativa correspondiente.

## Payload base

```json
{
  "operacion_id": "uuid",
  "numero_folio": "10293847",
  "tipo_bulto": "pallet",
  "cantidad_cajas": 120,
  "temporada_id": "uuid",
  "catalogo_version": 12,
  "categoria_validacion_id": "uuid",
  "articulo_validacion_id": "uuid",
  "origen_validacion_id": "uuid",
  "resultado": "aprobado",
  "motivo": null,
  "observacion": null,
  "generado_dispositivo_at": "2026-07-23T11:42:00-04:00"
}
```

## Interfaces implementadas

- Oficina principal para historial, métricas e importaciones.
- Oficina jerárquica para administración del catálogo.
- Aplicación móvil vertical.
- Catálogo local y bandeja offline.
- Integración completa con el nacimiento del folio y el flujo de Prefrío.

## Usuario local

En `local` y `testing`:

- usuario: `validador@estiba.local`;
- contraseña: `password`;
- dispositivo: `TABLET-01`.

## Pendientes

- matriz comercial más específica si negocio restringe combinaciones actualmente proyectadas;
- evidencia fotográfica;
- integración ERP;
- procedimiento administrado para reabrir una decisión terminal cuando exista una causa autorizada.

## Trazabilidad a materia prima

Cada línea de la composición del bulto puede informar, además del CSG y sus cajas, el
**lote de materia prima** y el **proceso de packing** impresos en la etiqueta del pallet:

```json
"composicion": [
  { "origen_validacion_id": "…", "cantidad_cajas": 80, "lote_materia_prima": "L-2401", "proceso_packing": "P-77" },
  { "origen_validacion_id": "…", "cantidad_cajas": 40, "lote_materia_prima": "L-2402", "proceso_packing": "P-77" }
]
```

- Un mismo CSG puede repetirse cuando aporta cajas de lotes o procesos distintos; la
  combinación CSG + lote + proceso no puede repetirse.
- Ambos campos se normalizan en mayúsculas. La PDA actual los exige en cada línea, salvo
  en un rechazo (la etiqueta puede faltar o ser ilegible). El servidor los exige cuando
  `WMS_VALIDACION_EXIGE_LOTE_PROCESO=true`; se activa después de instalar ese APK en todas
  las PDA, porque las versiones anteriores no los envían y serían rechazadas con `422`.
- Si no vienen, el hash del payload no cambia, por lo que las PDA anteriores conservan su
  idempotencia mientras la exigencia esté desactivada.
- El proceso de packing es el **número de orden** de Fruta a Proceso: la consulta muestra
  qué lotes MP se entregaron a esa orden en la temporada.
- La composición guarda solo el número impreso. El vínculo con un lote digitado en
  Materia Prima se calcula en la proyección y solo se afirma cuando coinciden número,
  temporada de origen y **cliente** (el cliente global del origen validado frente al del
  lote), y existe un único lote vigente. La temporada de origen es la del origen validado
  (la temporada en que se embaló): aunque el folio cambie de temporada, su trazabilidad
  nunca se reinterpreta con los lotes de otra temporada. Si no se puede verificar, la consulta muestra el número
  con la advertencia «recepción MP no verificada» y no atribuye ninguna recepción.
- Si el lote se digita después de validar el pallet, o cambia su número, cliente o estado
  (por ejemplo, se anula), los vínculos afectados se concilian automáticamente.
- La corrección de un solo CSG y los repaletizajes conservan lote y proceso en cada línea;
  la clave de composición del repaletizaje los incluye para no fusionar lotes distintos. La
  corrección con composición reemplaza lote y proceso con lo que indique cada línea.

La tabla `trazabilidad_folio_origenes` proyecta esa composición (una fila por línea) y se
reconstruye automáticamente cada vez que cambia `folios.datos_externos`. La oficina
**Consultas → Trazabilidad de lotes** (`GET /api/consultas/trazabilidad?q=`) busca por lote,
proceso de packing o folio y muestra lotes MP con su recepción `REC-*`, folios, ubicación,
carga y composición.

- Cada consulta corresponde a una sola temporada (la activa por defecto): los números de
  lote y proceso se repiten entre temporadas y nunca se mezclan. Las temporadas cerradas
  siguen disponibles en el selector, porque la trazabilidad es historial y no se borra
  al cerrar la temporada.
- El resumen cuenta **todos** los folios afectados de la temporada, los activos, las
  cajas del lote o proceso buscado y las líneas sin recepción verificada.
- El listado se pagina de 50 en 50 (`&pagina=`).
- Para decidir el alcance de un retiro se usa **Descargar todos los folios (Excel)**
  (`GET /api/consultas/trazabilidad/exportar?q=`): una fila por línea de composición de
  cada folio afectado, sin límite, con ubicación, carga y la marca «Línea buscada».
- La tabla protege sus referencias: ningún borrado de folios, lotes o clientes la
  elimina ni la modifica de forma implícita.
- El reinicio controlado de datos de prueba solo descarta la trazabilidad de los folios
  que él mismo elimina. Si un pallet de otra temporada depende de un lote que se
  eliminaría, el reinicio se bloquea sin borrar datos.
