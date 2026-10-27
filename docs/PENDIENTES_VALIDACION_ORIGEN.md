# Pendientes por validar (stock del sistema de origen)

**Frigorífico → Pendientes por validar** (`/oficina/validacion/pendientes`) compara los
pallets que el sistema de origen tiene en la bodega de la planta con lo validado en
FoliOS. Responde «qué falta por validar» sin cambiar la operación: **Validación sigue
siendo la única puerta a Frigorífico** y nada de lo leído crea, modifica ni bloquea folios.

## De dónde sale

- La conexión se configura en Administración → Accesos → Conexiones externas
  ([CONEXIONES_EXTERNAS.md](CONEXIONES_EXTERNAS.md)).
- Desde **Explorar origen → Configurar para pendientes por validar** se elige la tabla
  o vista de stock, una fila por línea de pallet, y se asignan sus columnas. El folio y
  las cajas son obligatorios; los demás campos son opcionales. Los filtros se agregan
  expresamente en el explorador: sin filtros se lee toda la fuente elegida.
- Encola una lectura cada cinco minutos (programador de Laravel, `integraciones:leer-stock-origen`) y
  cuando alguien pulsa **Leer ahora**. Un proceso exclusivo la ejecuta y la pantalla
  muestra su avance mientras permite seguir trabajando con la copia local. Activarlo
  según [INTEGRACIONES_EN_COLA.md](INTEGRACIONES_EN_COLA.md). La consulta se construye en el servidor con
  nombres descubiertos y valores parametrizados, dentro de una transacción de solo
  lectura que se revierte. La muestra y la lectura usan las mismas asignaciones.
- Cambiar el mapeo o la conexión exige una nueva lectura correcta antes de mostrar esa
  selección. No se mezclan copias ni estados de lectura de configuraciones distintas.
  Hasta 50.000 líneas por lectura; si se supera el límite debe acotarse la fuente.
  Una fila con folio vacío, demasiado largo o cajas inválidas hace fallar la lectura
  completa y conserva la copia anterior. Lo mismo ocurre con una respuesta vacía.

**Compatibilidad:** si todavía no hay mapeo guardado, se conserva la lectura anterior
de `odbc_stock`, bodega `AR01` y tipo `PT`, controlada por estas variables. Un mapeo
guardado tiene prioridad; si se deshabilita no se vuelve automáticamente a las variables.

| Variable | Por defecto | Uso |
|---|---|---|
| `WMS_STOCK_ORIGEN_CONEXION` | vacío | Nombre del perfil. Vacío: el único perfil activo. |
| `WMS_STOCK_ORIGEN_TABLA` | `odbc_stock` | Tabla o vista del origen (letras, números y `_`). |
| `WMS_STOCK_ORIGEN_BODEGA` | `AR01` | Bodega del origen que corresponde a la planta. |
| `WMS_STOCK_ORIGEN_TEMPORADA` | vacío | `cod_temporada` a leer. Vacío: todo el stock. |
| `WMS_STOCK_ORIGEN_HABILITADA` | `true` | Apaga la lectura sin borrar la conexión. |

## Cómo se interpreta el origen

Los nombres de la siguiente tabla son campos del contrato de FoliOS: pueden asociarse
a columnas con otros nombres. El origen trae una fila por línea; FoliOS las agrupa por **número de pallet**, que es el
mismo número del folio en FoliOS:

| Origen | FoliOS |
|---|---|
| `Pallet` | N° de folio (conserva ceros iniciales) |
| `disponibilidad` (opcional) | Valor del origen; NULL = por confirmar, no decide disponibilidad operativa |
| `cod_estado_stock_paletizado` PC / PI | pallet / saldo (si un pallet mezcla ambos, manda la mayoría de cajas) |
| suma de `cantidad` y `kilos_netos` | cajas y kilos |
| `cod_empresa` | cliente (exportadora) |
| `cod_especie` / `descripcion_especie`, `variedad` / `codigo_variedad`, `cod_calibre` (código, no rango), `cod_categoria`, `marca` | artículo |
| `cod_articulo` / `descripcion_articulo` / `peso_neto_articulo` | embalaje (envase) y kilos netos por caja |
| `codigo_csg_predio` / `descripcion_predio` + `lote_recepcion` + `Proceso` (código del sistema) + productor + variedad | una línea de composición |
| `fecha_embalaje`, `fecha_recepcion` (hora de planta) | embalaje y «en bodega desde» |

En la bodega de la planta cada pallet tiene un solo artículo y mezcla solo CSG, lote,
proceso y productor, igual que la composición de Validación. Si un pallet llega con
calibre, variedad u otro dato del artículo mezclado, se marca como «mezcla en origen».
`lote_recepcion` vacío o en ceros se trata como «sin lote».

## Qué muestra

- **Pendientes:** en el stock del origen y sin ningún intento de validación. Ordenados
  por antigüedad en bodega. La columna **Composición** muestra las cajas de cada CSG (con
  su predio) y, dentro de cada CSG, las cajas por lote y proceso: lo que el validador debe
  encontrar en la etiqueta. El CSG con más cajas va primero. **Solo en FoliOS** muestra la
  misma distribución, tomada de la composición validada.
- **Por resolver:** hubo intento, pero no quedó un folio vigente: observado, rechazo
  definitivo, conflicto o validación anulada. Manda el último intento.
- **Con diferencias:** conciliaciones entre planta y la validación física, con respaldo
  e historial (ver abajo). La comparación adicional con el stock actual de FoliOS se
  conserva desplegable: incluye tipo, cliente, artículo y estado operacional posterior.
  La categoría continúa siendo una sugerencia.
- **Solo en FoliOS:** folios nacidos en Validación, todavía en planta, que el origen no
  tiene en bodega: un número mal digitado o un traspaso que el origen aún no registra.
  Solo se calcula cuando ya existe una lectura correcta.
- **Catálogo:** datos del origen que el catálogo de validación de la temporada activa
  todavía no reconoce (ver abajo).

**Excel** descarga las cuatro listas de pallets en una hoja, con la situación de cada
pallet, la categoría sugerida, lo que falta en el catálogo, las cajas por CSG y la
composición (CSG · lote · proceso: cajas).

## Cuadraturas de la validación con planta

Este seguimiento es independiente de las discrepancias operacionales y del stock
actual del folio. Conserva las diferencias detectadas al validar para que Oficina
pueda cuadrar el sistema planta.

| Campo | Comparación |
|---|---|
| Cajas | Total físico validado frente al total de planta; diferencia = FoliOS − planta. |
| Marca | Marca validada frente a la equivalencia del catálogo de la marca de planta. |
| Calibre | Calibre validado frente a la equivalencia del catálogo del calibre de planta. |
| CSG | Cajas agrupadas por código CSG; el total igual no oculta otra distribución. Se conservan los ceros iniciales. |

El registro aprobado que crea un folio captura el snapshot completo del validador,
su identidad, dispositivo, intento y fechas, junto con la copia de planta disponible
en el servidor, su lectura y equivalencias. Desde la precarga en la PDA, el snapshot
del validador incluye `precarga_origen`: la huella de la copia de planta que la PDA mostró,
los campos que el validador corrigió y si esa copia seguía vigente al registrar. Sin
`precarga_origen` (PDA anterior o sin conexión), la captura no acredita que la PDA haya
mostrado los datos de planta.
Las validaciones anteriores se incorporan con **Leer ahora**, la lectura programada
o **Registrar / recomprobar conciliaciones**; se identifican como primera comparación
posterior, sin reconstruir una lectura histórica que no existe. Los GET no escriben.

Los respaldos iniciales no se sustituyen. Una corrección autorizada de Validación
actualiza la comparación vigente y deja la anterior en el historial. Los movimientos,
despachos y repaletizajes posteriores del folio no cambian la base física validada.
Si el folio cambió o salió de planta, se indica esa situación y se suspende el cierre
o reapertura automática de la conciliación.

| Estado | Significado |
|---|---|
| Pendiente de cuadratura | Hay diferencias conocidas con planta. |
| En revisión | Oficina tomó el caso o informó una corrección en planta. |
| Cuadrado | Después de haber diferencias, una lectura posterior vigente coincide en los cuatro campos. |
| Reabierto | Un caso cuadrado vuelve a tener diferencias en una lectura vigente. |
| Sin diferencias | La comparación completa coincide y el caso nunca tuvo diferencias. |
| Por revisar | No hay evidencia completa para confirmar la comparación. |

Una gestión guarda usuario, comentario obligatorio, referencia opcional y fecha;
**no cierra manualmente** el caso. Los envíos usan UUID y versión para evitar
operaciones duplicadas o sobrescribir una lectura más reciente. Ante una respuesta
perdida la pantalla conserva el mismo envío y permite reintentarlo.

La ausencia del pallet, una lectura fallida, en curso o de más de 20 minutos, campos
sin equivalencia o composiciones incompletas nunca prueban una cuadratura. El estado
histórico se conserva y la pantalla muestra que la comprobación requiere revisión.
**Registrar / recomprobar** usa la copia disponible; **Leer ahora** consulta planta.
Si falla el cálculo derivado, se conserva la lectura correcta y se avisa que quedan
conciliaciones por recomprobar. Cada caso se procesa en su propia transacción.

La lista y su Excel admiten filtros por folio exacto, estado, campo con diferencia y
fechas de validación en hora de Chile. El Excel incluye los cuatro campos, respaldos
originales, valores vigentes, lectura, fuente, contexto e identidad del validador.
El detalle conserva el historial paginado. La fuente y temporada activas son el alcance
por defecto; se pueden incluir fuentes anteriores. La API admite `temporada_id` para
consultas históricas. Los casos de temporadas cerradas y fuentes anteriores son de
consulta; no admiten gestiones nuevas. Los respaldos permanecen aunque se purgue el
registro técnico de la lectura de origen.

Para desplegar: ejecutar las migraciones (`php artisan migrate --force`) y compilar
la interfaz (`npm ci && npm run build`). No se escribe en la base de planta.

## Catálogo: el origen alimenta los maestros de temporada

Igual que las consultas SAG traen CSG, especies y variedades, el stock del origen trae
clientes, marcas, especies, variedades, calibres, embalajes, categorías y CSG. Para
comparar, cada dato del origen tiene que corresponder a un elemento del catálogo de
validación de la temporada activa. FoliOS lo busca en este orden:

1. **Un vínculo guardado** (alguien ya dijo «C1 del origen es CAT1»).
2. **El código externo** del catálogo: el embalaje por `cod_articulo`, la especie por
   `cod_especie`, el cliente por `cod_empresa` (código, código externo o alias del
   maestro de clientes).
3. **El mismo nombre escrito tal cual**, sin distinguir mayúsculas, tildes ni espacios
   (marca, variedad, calibre). La especie acepta singular y plural («CEREZAS» = Cereza).

Lo que no se reconoce aparece en la pestaña **Catálogo**, una vez por valor, con los
pallets y cajas que dependen de él. Mientras no se resuelva, ese dato no se compara y la
pantalla lo marca como «falta en catálogo» en cada pallet. Nada se agrega solo: quien
administra el catálogo de validación decide.

| Dato | Se reconoce por | Vincular | Agregar al catálogo |
|---|---|---|---|
| Cliente | `cod_empresa` | Sí: queda como alias del cliente (origen `stock_origen`), también para las próximas temporadas | No: el cliente se crea en Clientes |
| Marca | nombre, dentro del cliente | Sí | Sí, tal cual, en el cliente |
| Especie | `cod_especie` o nombre | Sí | Sí, con mayúscula inicial y su código externo |
| Variedad | nombre, dentro de la especie | Sí | Sí, con mayúscula inicial y su código externo |
| Calibre | código, dentro de la especie | Sí | Sí |
| Embalaje | `cod_articulo`, dentro de especie y cliente | Sí | Sí: nombre = descripción, código externo = `cod_articulo`, kilos netos = `peso_neto_articulo` |
| Categoría | código o vínculo | Sí: varias nomenclaturas pueden ir a la misma categoría | No: solo se sugiere |
| CSG | código | — | Sí, con su predio y las variedades que trae |
| Variedad en CSG | CSG + variedad | — | Sí: autoriza la variedad en el CSG |

La marca y el embalaje dependen del cliente, y variedad, calibre y embalaje dependen de
la especie: primero se resuelve el cliente o la especie. Lo agregado queda en Maestros de
temporada como cualquier otro elemento, sube la versión del catálogo y llega a las PDA en
la siguiente sincronización. Cada vínculo e incorporación queda registrado con quién lo
hizo (tabla `homologaciones_stock_origen`, por temporada).

Si un pallet mezcla variedades o calibres, cada valor se revisa por separado. Cliente,
especie y embalaje mezclados no se pueden separar sin perder el par de código y
descripción, así que no se proponen.

## Lecturas y fallas

- Cada lectura queda registrada con fecha, resultado, filas, pallets nuevos, modificados
  y retirados, y la causa si falla (regla 16.6). Se conservan 30 días.
- La copia guarda cada pallet con su última versión. Un pallet que deja de aparecer queda
  como «salió de bodega» (no se borra) y vuelve si reaparece.
- Si el origen devuelve **cero filas** para la bodega, la lectura se marca fallida y se
  conserva la anterior: una vista caída no debe dar todo por despachado.
- La pantalla avisa si la última lectura correcta tiene más de 20 minutos
  (`WMS_STOCK_ORIGEN_VIGENCIA_MINUTOS`).
- Las causas se informan sin servidor, usuario ni clave: falta pdo_mysql, servidor
  inaccesible, acceso denegado, sin permiso, base o tabla inexistente, columna faltante,
  tiempo agotado.

## Permisos

Consulta, lectura inmediata y Excel: `consultar-validaciones-pallet`, los mismos perfiles
que ven Validación. «Leer ahora» admite cuatro solicitudes por minuto por usuario.
Gestionar y recomprobar conciliaciones: `gestionar-conciliaciones-validacion`, limitado
a Administrador o Supervisor de Frío con acceso a Validación; Consulta y Validador
conservan consulta y Excel.
Vincular o agregar al catálogo: además, `administrar-catalogos-validacion`. Quien solo
consulta ve lo que falta, sin las acciones.

## Precarga en la PDA

La PDA parte la validación con estos mismos datos de planta, traducidos al catálogo,
incluida la categoría sugerida. El validador confirma el pallet o corrige lo que no
coincide. Ver *Precarga con los datos de planta* en
[Validación de pallets](MODULO_VALIDACION_PALLETS.md).

## Pendiente

- Procesos de packing desde el origen.
