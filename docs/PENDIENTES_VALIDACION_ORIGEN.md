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
| `descripcion_especie`, `variedad`, `cod_calibre` (código, no rango), `cod_articulo` / `descripcion_articulo`, `cod_categoria`, `marca` | artículo |
| `codigo_csg_predio` + `lote_recepcion` + `Proceso` (código del sistema) + productor | una línea de composición |
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
- **Con diferencias:** validado, pero no coincide en tipo (pallet/saldo), cajas, calibre
  o cajas por CSG, o el folio ya no está en planta (despachado, agotado, retirado)
  mientras el origen lo sigue mostrando en bodega.
- **Solo en FoliOS:** folios nacidos en Validación, todavía en planta, que el origen no
  tiene en bodega: un número mal digitado o un traspaso que el origen aún no registra.
  Solo se calcula cuando ya existe una lectura correcta.

Variedad, envase y categoría se muestran pero no se comparan todavía: los catálogos usan
textos y códigos distintos (por ejemplo, «C1» y «CAT1»). Se podrán comparar cuando los
maestros de temporada tengan cargado su `codigo_externo`.

**Excel** descarga las cuatro listas en una hoja, con la situación de cada pallet.

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

## Pendiente

- Precargar la validación en la PDA con los datos del origen.
- Comparar variedad, envase y categoría con `codigo_externo` de los maestros.
- Procesos de packing y catálogo de validación desde el origen.
