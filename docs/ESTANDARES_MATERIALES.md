# Estándares de consumo de materiales

**Materiales → Estándares de consumo** (`/oficina/materiales/estandares`) define cuánto
material lleva cada caja de un embalaje PT de un cliente. Es la base de la rebaja por
estándar: descontar materiales según lo validado en Validación PT (día de proceso,
cliente, marca, proceso y cajas).

Entregas del plan:

1. Estándares propios con versiones (esta entrega).
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

## Permisos

Módulo de oficina `materiales.estandares` y acción `estandares.administrar`.

| Capacidad | Gate | Perfiles predeterminados |
|---|---|---|
| Consultar y exportar | `consultar-estandares-materiales` | Administrador, supervisor de materiales, despachador, consulta |
| Crear, versionar, copiar, activar | `administrar-estandares-materiales` | Administrador y supervisor de materiales (acción `estandares.administrar`) |

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
```

Cada línea lleva `item_material_id`, `cantidad_por_caja`, `redondeo` (`entero`|`decimal`)
y, si corresponde, `variacion_tipo` (`marca`|`variedad`|`calibre`|`categoria`) y
`variacion_valor`. Una `version` que ya no es la vigente responde 409. Solo se crean y
versionan estándares de la temporada activa.

El Excel trae una fila por insumo de la versión que rige hoy, con la cantidad por caja
en formato de hasta 6 decimales (tipo de columna `decimal` del generador XLSX).

## Despliegue

`php artisan migrate --force` (crea las tablas y habilita el módulo) y `npm run build`.
No requiere OTA. El reinicio operacional de temporada conserva los estándares, igual que
el resto de los catálogos de Bodega.
