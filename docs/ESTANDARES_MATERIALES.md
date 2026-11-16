# Estándares de consumo de materiales

**Materiales → Estándares de consumo** (`/oficina/materiales/estandares`) define cuánto
material lleva cada caja de un embalaje PT de un cliente. Es la base de la rebaja por
estándar: descontar materiales según lo validado en Validación PT (día de proceso,
cliente, marca, proceso y cajas).

Entregas del plan:

1. Estándares propios con versiones.
2. Importación desde la vista de planta `odbc_estandar_materiales` y homologación de insumos.
3. Rebaja por estándar desde Validación PT, contra el saldo entregado a Packing.
4. Diferencias por correcciones posteriores y reportes.

## Modelo

| Tabla | Contenido |
|---|---|
| `estandares_materiales` | Temporada, cliente (maestro global), embalaje PT (`envases_validacion`), origen `planta` o `propio`, código, descripción, código de artículo de planta, estado y versión para edición concurrente. Único por temporada + cliente + embalaje + origen. |
| `versiones_estandares_materiales` | Versión inmutable: número, **vigente desde** (fecha de proceso), origen, motivo, huella de planta y snapshot. |
| `lineas_estandares_materiales` | Ítem de Materiales (o código de planta sin homologar), cantidad por caja con hasta 6 decimales, rebaja **Entero** o **Decimal** y una variación opcional. |

La clave del estándar es el **embalaje PT del cliente**, igual que en planta, donde el
estándar se identifica por empresa + `cod_articulo`. El código de estándar es solo un
nombre: en la muestra de planta hay códigos que cubren dos artículos.

## Reglas

- **Propio sobre planta.** Un embalaje puede tener un estándar de planta y uno propio.
  La rebaja usa el propio activo que rija en la fecha de proceso. Si no existe, o empieza
  después, usa el de planta. El de planta queda como referencia.
- **Versiones.** Una versión no se modifica. Un cambio crea otra que rige desde su fecha,
  que no puede ser anterior a la de la última versión. Para cada caja rige la versión
  con la fecha más reciente que no sea posterior a su fecha de proceso.
- **Planta se actualiza desde planta.** Un estándar de planta no se versiona a mano:
  **Copiar como propio** crea el propio con las mismas líneas. Exige que todos sus insumos
  estén homologados con un ítem de Materiales.
- **Dueño del insumo.** El ítem puede ser de otro dueño que el cliente del PT (por ejemplo,
  etiquetas de la planta, que en la muestra aparecen en estándares de 7 clientes). Debe
  ser un ítem activo de Materiales de la misma temporada.
- **Entero / Decimal.** No describe la cantidad por caja, que puede tener decimales en
  ambos casos (pallet 0,0049 por caja). «Entero» descuenta unidades completas y arrastra
  la fracción a la próxima rebaja del mismo ítem. «Decimal» descuenta la cantidad exacta.
- **Variaciones.** Una línea puede limitarse a una marca, variedad, calibre o categoría.
  Cuando la caja calza, esa línea **reemplaza** la línea general del mismo ítem. Si
  calzan varias, manda marca, luego variedad, calibre y categoría. Una línea con
  variación sin línea general solo se consume en las cajas que calzan. Los valores se
  comparan en mayúsculas y sin espacios repetidos.

## Estándares de planta

La sección **Estándares de planta** de la misma pantalla lee la vista
`odbc_estandar_materiales` del sistema de planta y la convierte en estándares de origen
`planta`.

### Configuración y lectura

- Un administrador de accesos elige la conexión externa, la tabla o vista
  (`odbc_estandar_materiales` por defecto), la **temporada de planta** (`cod_temporada`,
  por ejemplo `T2627`) y, si quiere, una lista de empresas. Se guarda cifrado en
  `configuraciones_origen` (clave `estandares_materiales`).
- **Leer ahora** encola una operación `estandares` en la cola exclusiva `integraciones`,
  igual que la lectura de stock. Solo hace un SELECT de columnas fijas: empresa, temporada,
  código y descripción del estándar, artículo, insumo, tipo de rebaja, cantidad, especie y
  fechas de creación y edición. Las columnas de creador y modificador (correos) no se leen.
- Una lectura con error, vacía o de más de 50.000 filas conserva la última copia correcta.
  La copia vive en `lecturas_estandares_origen` y `estandares_origen`, y solo se guarda la
  de la última lectura correcta.

### Cómo se interpreta la vista

La vista no trae el identificador del estándar ni sus variaciones. En la muestra de
noviembre de 2026, 14 artículos aparecían con 2 o 3 cabeceras que solo se distinguen por la
fecha de creación (6 con cantidades distintas) y había 3 filas repetidas exactas. Por eso:

- una fila repetida exacta se cuenta una vez;
- de las cabeceras de un mismo artículo se toma la creada más tarde (a igual creación, la
  editada más tarde) y se informa cuántas se descartaron;
- un insumo repetido dentro de la cabecera elegida se **suma** (por ejemplo, etiqueta 1 +
  0,01 por caja) y se marca como sumado;
- las cantidades llegan como flotantes (0,004900000058) y se guardan con 6 decimales;
- `tipo_rebaja_insumo` Entero o Decimal se conserva; un valor distinto se toma como Decimal.

Cuando TI agregue a la vista el identificador del estándar, su vigencia y las columnas de
variación, la lectura podrá usarlas sin depender de la fecha de creación.

### Vínculos e incorporación

- **Cliente** (`cod_empresa`) y **embalaje** (`cod_articulo`) se reconocen igual que en
  Pendientes por validar, con sus mismos vínculos. Si falta, se vinculan desde esta sección:
  el cliente crea el alias en el maestro y el embalaje queda reconocido también para el stock.
- **Insumo** (`cod_insumo`): un vínculo guardado por empresa o, si no hay, el ítem de
  Materiales de la temporada cuyo código o código externo coincide. Primero se busca en el
  catálogo del cliente; si hay varios candidatos, se elige uno y queda vinculado
  (`homologaciones_stock_origen`, dimensión `insumo_material`).
- **Incorporar listos** crea o actualiza los estándares de planta con cliente y embalaje
  reconocidos. Un insumo sin ítem se incorpora sin ítem: la rebaja no podrá descontarlo y
  no se puede copiar como propio hasta vincularlo.
- Una versión nueva se crea solo si cambió el estándar en planta o se vinculó un insumo.
  La primera rige desde la **creación** en planta. Las siguientes rigen desde la
  **edición** en planta, nunca antes que la versión anterior.

## Permisos

Módulo de oficina `materiales.estandares` y acción `estandares.administrar`.

| Capacidad | Gate | Perfiles predeterminados |
|---|---|---|
| Consultar y exportar | `consultar-estandares-materiales` | Administrador, supervisor de materiales, despachador, consulta |
| Crear, versionar, copiar, activar; leer planta, vincular e incorporar | `administrar-estandares-materiales` | Administrador y supervisor de materiales (acción `estandares.administrar`) |
| Configurar la lectura de planta | `administrar-accesos` | Administrador de accesos |

La migración `2026_11_16_120100_habilitar_estandares_materiales_en_perfiles` agrega el
módulo a esos perfiles predeterminados. Los perfiles personalizados lo reciben desde
Accesos.

## API

```
GET  /api/materiales/estandares?temporada_id=&cliente_id=&origen=&estado=activos|inactivos|todos&q=
GET  /api/materiales/estandares/opciones?temporada_id=&cliente_id=
GET  /api/materiales/estandares/items?q=&cliente_id=
GET  /api/materiales/estandares/excel?…mismos filtros…
GET  /api/materiales/estandares/{estandar}
POST /api/materiales/estandares                       (cliente_id, envase_validacion_id, codigo?, descripcion?, vigente_desde?, motivo?, lineas[])
POST /api/materiales/estandares/{estandar}/versiones  (version, vigente_desde?, motivo?, codigo?, descripcion?, lineas[])
POST /api/materiales/estandares/{estandar}/copiar     (vigente_desde?, motivo?)
PUT  /api/materiales/estandares/{estandar}/estado     (activo, version)

GET  /api/materiales/estandares/planta                          estado, última lectura, revisión e insumos pendientes
PUT  /api/materiales/estandares/planta/configuracion            (conexion_id, tabla, temporada_origen, empresas[], habilitada, version_conocida)
POST /api/materiales/estandares/planta/lecturas                 202 con la operación en cola
POST /api/materiales/estandares/planta/vinculos/clientes        (cod_empresa, cliente_id)
POST /api/materiales/estandares/planta/vinculos/embalajes       (estandar_origen_id, envase_validacion_id)
POST /api/materiales/estandares/planta/vinculos/insumos         (cod_empresa, cod_insumo, item_material_id)
POST /api/materiales/estandares/planta/incorporar               (ids? opcional)
```

Cada línea lleva `item_material_id`, `cantidad_por_caja`, `redondeo` (`entero`|`decimal`)
y, si corresponde, `variacion_tipo` (`marca`|`variedad`|`calibre`|`categoria`) y
`variacion_valor`. Una `version` que ya no es la vigente responde 409. Solo se crean y
versionan estándares de la temporada activa.

El Excel trae una fila por insumo de la versión que rige hoy, con la cantidad por caja
en formato de hasta 6 decimales (tipo de columna `decimal` del generador XLSX).

## Despliegue

`php artisan migrate --force` (crea las tablas y habilita el módulo) y `npm run build`.
No requiere OTA. La lectura de planta usa el proceso exclusivo de la cola `integraciones`
([Integraciones en cola](INTEGRACIONES_EN_COLA.md)); reiniciarlo después de actualizar.
El reinicio operacional de temporada conserva los estándares, igual que el resto de los
catálogos de Bodega.
