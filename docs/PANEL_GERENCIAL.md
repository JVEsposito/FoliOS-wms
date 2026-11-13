# Panel gerencial

`/oficina/gerencia` · API `GET /api/gerencia/resumen` (`consultar-panel-gerencial`). Solo lectura; la
instantánea se guarda en caché (`gerencia.cache_segundos`) y se invalida con los cambios operacionales.

## Kilos recepcionados

- Cuentan las recepciones de Romana **cerradas** y no eliminadas, sin las de tipo `solo_envases`.
- Muestra el total de la temporada, los últimos 7 días y hoy. Por cliente muestra kilos de la temporada,
  participación, 7 días, hoy y número de recepciones.
- El gráfico de 7 días apila a los cinco clientes con más kilos de la semana. El resto va en «Otros clientes».
- Cada recepción cuenta en el **día de ingreso** del camión, en hora de planta (`app.operational_timezone`),
  igual que el filtro de fechas de Romana y Finanzas. Si un camión entra a las 22:38 y se destara pasada la
  medianoche, sus kilos quedan en el día en que entró. Mientras no se cierra no suma; al cerrarse se suma a
  su día de ingreso.
- «Recepciones cerradas hoy» y «clientes hoy» cuentan las que ingresaron hoy y ya se cerraron, e incluyen las
  de solo envases.

## Producto terminado: pallets y saldos

Un **pallet** es un bulto completo. Un **saldo** es un bulto parcial que espera consolidarse. El panel
nunca los suma como si fueran lo mismo:

- Las tarjetas muestran pallets, saldos y cajas por separado.
- Cada estado (disponibles, comprometidos en carga, pendientes de prefrío, sin ubicación, bloqueados,
  otros) informa pallets, saldos y cajas. En la barra, el pallet es relleno y el saldo es trama.
- Las cajas salen de `datos_externos.cantidad_cajas` del folio.
- «Por exportadora» agrupa el texto `folios.exportadora` sin distinguir mayúsculas ni espacios extremos.
  Muestra las seis con más cajas y resume el resto.
- La antigüedad desde el ingreso también separa pallets y saldos por tramo.

`productos()` hace como máximo tres consultas (conteos, cargas comprometidas y exportadoras).

## Cámaras

Todo el panel muestra el **nombre** de la cámara. El código queda como dato secundario en la tabla de
capacidad. La ocupación se agrupa por área: producto terminado, materia prima y materiales. Se marca en
ámbar desde 75 % y en rojo desde 90 %.

## Focos

Cada foco indica su nivel en palabras (Crítico, Atención, Aviso) y la cifra con su unidad. El encabezado
resume cuántos hay de cada nivel.

Pruebas: `tests/Feature/Api/PanelGerencialApiTest.php`, `tests/Browser/OfficeManagementDashboardTest.mjs`,
`tests/JavaScript/ManagementProductStatesTest.mjs`.
