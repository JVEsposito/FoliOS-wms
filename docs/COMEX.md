# Comex

Comex lleva el **expediente de cada embarque**: qué documentos pide según su destino, su
transporte y el cliente, cuáles están listos, con su número y respaldo, y qué falta antes de
que la carga salga. Lee el calendario de embarques y sus cargas **sin modificarlos**: la
operación no sabe que Comex existe.

Sirve en los dos casos de una planta: cuando presta el servicio a exportadores (sigue los
documentos de cada uno) y cuando la empresa exporta por su cuenta. Todo lo que pide se
configura, de modo que otra planta con otros mercados lo use sin cambiar código.

## Cómo se separa de la operación

| Pieza | Dónde | Regla |
| --- | --- | --- |
| Base de datos | Conexión `comex` (por omisión, `<DB_DATABASE>_comex` en el mismo servidor) | Solo Comex escribe aquí. Tablas con prefijo `cx_`. |
| Lectura de la operación | Conexión `operacion_lectura` (la misma de Finanzas) | Sesión `READ ONLY`: la base rechaza cualquier escritura. |
| Código | `app/Comex` | Nada fuera de `app/Comex` lo referencia, tampoco Finanzas. |
| Rutas | `routes/comex.php` (`/api/comex/...`) | Leen la operación y escriben solo en la base de Comex. |
| Respaldos | Disco `local`, carpeta `comex/documentos/<embarque>` | Se descargan sin caché; reemplazar uno conserva el anterior. |
| Oficina | Administración → Comercio exterior → *Expedientes de embarque* y *Configuración Comex* | Módulos de acceso `comex.expedientes` y `comex.configuracion`. |

Las tablas de Comex no tienen llaves foráneas hacia la operación: guardan el identificador
del embarque y del cliente, con una copia del código del embarque y del nombre del cliente.

La base propia la protege la misma base común de Finanzas (`app/Gestion`): cada módulo de
gestión registra su esquema y, al migrar, sus tablas se vacían con un `migrate:fresh`
confirmado de la base operativa o se detienen si quedaron datos sin su registro de
migraciones.

`tests/Feature/Architecture/ComexArchitectureTest.php` vigila estas reglas: ni la operación
ni Finanzas importan nada de Comex, Comex no depende de Finanzas, sus modelos viven en su
base, consulta la operación solo por `LectorOperacion` y ninguna migración cruza llaves entre
las bases. `ExpedientesComexApiTest` comprueba que el panel, el detalle, el registro de un
documento y el packing list solo ejecutan `SELECT` sobre la base operativa.

## Lo que lee de la operación

| Dato | Tabla | Para qué |
| --- | --- | --- |
| Embarque | `embarques` | Fecha y hora, modalidad, estado, nave o vuelo, puerto, contenedor, sello, patentes. |
| Instructivos | `instructivos_embarque` | País de destino (decide los documentos por destino), recibidor, booking, DUS, planilla SAG. |
| Carga | `cargas`, `carga_folios` | Si ya salió de la planta y qué pallets lleva. |
| Pallets | `folios` (`datos_externos.composicion`) | Especie, variedad, calibre, envase, CSG, predio, fecha de embalaje y cajas del packing list. |
| Kilos por caja | `envases_validacion` (por el envase o el artículo de validación) | Kilos netos del packing list. |
| Catálogos | `clientes`, `paises`, `puertos` | Nombres y códigos ISO. |

## Configuración

### Documentos

Cada documento define cuándo se exige:

| Campo | Valores | Efecto |
| --- | --- | --- |
| Se necesita | Antes de la salida · Después de la salida | Lo que falta *antes* marca el embarque como urgente o salido incompleto. |
| Se exige | Siempre · Solo en algunos destinos · Opcional | Con *algunos destinos*, solo si un instructivo va a uno de los países elegidos (código ISO). |
| Transporte | Marítimo, aéreo, terrestre | Si el embarque está *por confirmar*, el documento queda *por definir*. |
| Clientes | Servicio, exportación propia | Por ejemplo, la factura de exportación solo en exportación propia. |
| Para marcarlo listo | Número, fecha de emisión, respaldo | Lo exige el servidor al marcarlo listo. |
| Dato de la operación | Booking, DUS, planilla SAG, sello SAG, SPS, instructivo, contenedor, sello | Se muestra como referencia y se propone como número. No lo marca listo. |

Una base nueva parte sin documentos. *Agregar sugeridos* carga un punto de partida para
fruta fresca (instructivo, booking, CRT, planilla SAG, DUS, guía de despacho, fitosanitario,
BL, guía aérea, factura de exportación, certificado de origen y termógrafo), que luego se
ajusta, desactiva o complementa. Un documento no se borra: se desactiva, y lo ya registrado
en los expedientes se conserva.

### Clientes

| Gestión | Uso |
| --- | --- |
| Servicio (por omisión) | La planta presta el servicio y sigue los documentos del exportador. |
| Exportación propia | La empresa exporta; se suman los documentos marcados para este caso. |
| Sin seguimiento | Sus embarques no aparecen en el panel. |

Los cambios de configuración valen desde ya para todos los expedientes.

## Expediente

Cada documento de un embarque está **pendiente** (sin registro), **en trámite**, **listo** o
**no aplica** (con motivo). Un documento listo cuyo tipo después pasó a pedir algo que no
tiene (por ejemplo, el respaldo) vuelve a contarse como pendiente.

- **Registro**: estado, número, fecha de emisión, observación y respaldo (PDF, JPG, PNG o
  WebP de hasta 10 MB) en una sola operación. El mismo archivo dos veces no se duplica.
- **Versión**: cada guardado compara la versión que vio el usuario; si otra persona cambió
  el documento entretanto, no se pisa y la oficina recarga el expediente.
- **Historial**: cada registro, cambio y respaldo queda con usuario y hora. Reemplazar un
  respaldo deja el anterior disponible.
- **Embarque cancelado**: su expediente se consulta, pero ya no se edita.
- **Temporada**: los documentos se completan aunque la temporada haya cambiado (un BL o un
  certificado llegan semanas después de la salida).

### Alertas del panel

| Alerta | Cuándo |
| --- | --- |
| Salió incompleto | Faltan documentos de antes de la salida y la carga ya salió (despachada o cerrada). |
| Urgente | Faltan documentos de antes de la salida y el embarque es en `COMEX_DIAS_ALERTA` días o menos (2 por omisión). |
| Pendiente | Falta algún documento exigido o hay documentos por definir. |
| Completo | Todos los documentos exigidos están listos o no aplican. |
| Cancelado / Sin seguimiento | Se ocultan por omisión y no cuentan en el resumen. |

El panel consulta hasta 93 días por vez (por omisión, desde una semana atrás hasta tres
semanas adelante).

### Packing list

*Packing list (Excel)* arma la planilla desde la carga del embarque: una fila por pallet, o
por línea de un pallet mixto, con especie, variedad, calibre, envase, categoría, marca,
exportadora, CSG, predio, fecha de embalaje, cajas y kilos netos. La hoja *Corte* trae los
datos del embarque (nave, contenedor, sello, recibidor, destino, booking) y los totales. Los
pallets descartados o reemplazados no se incluyen; si un envase no tiene kilos por caja, la
línea queda sin kilos y el total lo advierte.

## Permisos

| Capacidad | Quién |
| --- | --- |
| Consultar expedientes y configuración | Administrador o Consulta con `comex.expedientes` o `comex.configuracion`, o un perfil que los incluya. |
| Registrar documentos | Módulo `comex.expedientes` (rol Administrador sin perfil, o cualquier perfil no de consulta con el módulo). |
| Configurar documentos y clientes | Módulo `comex.configuracion`. |

El perfil inicial de Administrador recibe ambos módulos. Un perfil de Comex típico: rol base
Despachador con *Expedientes de embarque*; quien define los documentos, también con
*Configuración Comex*.

## Instalación en el servidor

1. `php artisan migrate --force` crea la base de Comex si el usuario de la conexión tiene
   permiso `CREATE`. Si no lo tiene, créela antes:

   ```sql
   CREATE DATABASE estiba_wms_comex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Comex lee la operación por `operacion_lectura`; el usuario de solo lectura recomendado en
   [Finanzas](FINANZAS.md#instalación-en-el-servidor) sirve para ambos módulos.

3. Los respaldos deben incluir **las tres bases** (operativa, Finanzas y Comex) del mismo
   momento y la carpeta `storage/app/private/comex` con los archivos de los documentos.

4. En *Configuración Comex*, agregue los documentos sugeridos o defina los propios, y
   marque los clientes de exportación propia o sin seguimiento.

| Variable | Por omisión | Uso |
| --- | --- | --- |
| `COMEX_DB_DATABASE` | `<DB_DATABASE>_comex` | Base de Comex. |
| `COMEX_DB_URL`, `_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD` | Los de `DB_*` | Servidor y usuario de la base de Comex. |
| `COMEX_CONEXION_LECTURA` | `operacion_lectura` | Conexión por la que lee la operación. |
| `COMEX_DIAS_ALERTA` | `2` | Días antes del embarque en que lo pendiente de antes de la salida es urgente. |

El reinicio controlado de datos de prueba (Accesos y temporadas) no toca Comex: los
documentos de embarques borrados quedan en su base, pero esos embarques ya no aparecen.

## Próximos pasos

1. Seguimiento y estadísticas: tiempos de cada documento, embarques por mercado y cliente.
2. Exportación propia: recibidores, condiciones de venta, Incoterm, moneda y los datos de la
   factura de exportación (que se emite en el sistema tributario).
3. Liquidaciones al recibidor y a los productores, con tipo de cambio y los servicios de
   Finanzas.
