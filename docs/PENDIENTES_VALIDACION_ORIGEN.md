# Pendientes por validar (stock del sistema de origen)

**Frigorífico → Pendientes por validar** (`/oficina/validacion/pendientes`) compara los
pallets que el sistema de origen tiene en la bodega de la planta con lo validado en
FoliOS. Responde «qué falta por validar» sin cambiar la operación: **Validación sigue
siendo la única puerta a Frigorífico** y nada de lo leído crea, modifica ni bloquea folios.

## De dónde sale

- La conexión se configura en Administración → Accesos → Conexiones externas
  ([CONEXIONES_EXTERNAS.md](CONEXIONES_EXTERNAS.md)).
- FoliOS lee la tabla o vista `odbc_stock` del origen: el stock actual de producto
  terminado, una fila por línea de pallet. Solo trae las filas de la bodega de la
  planta (`cod_bodega`, por defecto `AR01`) y de tipo `PT`, y solo las columnas que usa.
- Lee cada cinco minutos (programador de Laravel, `integraciones:leer-stock-origen`) y
  cuando alguien pulsa **Leer ahora**. La consulta es un `SELECT` fijo, dentro de una
  transacción de solo lectura que se revierte.

| Variable | Por defecto | Uso |
|---|---|---|
| `WMS_STOCK_ORIGEN_CONEXION` | vacío | Nombre del perfil. Vacío: el único perfil activo. |
| `WMS_STOCK_ORIGEN_TABLA` | `odbc_stock` | Tabla o vista del origen (letras, números y `_`). |
| `WMS_STOCK_ORIGEN_BODEGA` | `AR01` | Bodega del origen que corresponde a la planta. |
| `WMS_STOCK_ORIGEN_TEMPORADA` | vacío | `cod_temporada` a leer. Vacío: todo el stock. |
| `WMS_STOCK_ORIGEN_HABILITADA` | `true` | Apaga la lectura sin borrar la conexión. |

## Cómo se interpreta el origen

El origen trae una fila por línea; FoliOS las agrupa por **número de pallet**, que es el
mismo número del folio en FoliOS:

| Origen | FoliOS |
|---|---|
| `Pallet` | N° de folio |
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
  por antigüedad en bodega.
- **Por resolver:** hubo intento, pero no quedó un folio vigente: observado, rechazo
  definitivo, conflicto o validación anulada. Manda el último intento.
- **Con diferencias:** validado, pero no coincide en tipo (pallet/saldo), cajas, cliente,
  marca, especie, variedad, calibre, embalaje o cajas por CSG, o el folio ya no está en
  planta (despachado, agotado, retirado) mientras el origen lo sigue mostrando en bodega.
  La categoría no cuenta como diferencia: se muestra como sugerencia.
- **Solo en FoliOS:** folios nacidos en Validación, todavía en planta, que el origen no
  tiene en bodega: un número mal digitado o un traspaso que el origen aún no registra.
  Solo se calcula cuando ya existe una lectura correcta.
- **Catálogo:** datos del origen que el catálogo de validación de la temporada activa
  todavía no reconoce (ver abajo).

**Excel** descarga las cuatro listas de pallets en una hoja, con la situación de cada
pallet, la categoría sugerida y lo que falta en el catálogo.

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
Vincular o agregar al catálogo: además, `administrar-catalogos-validacion`. Quien solo
consulta ve lo que falta, sin las acciones.

## Pendiente

- Precargar la validación en la PDA con los datos del origen, incluida la categoría
  sugerida.
- Procesos de packing desde el origen.
