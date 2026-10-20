# Finanzas

Finanzas valoriza lo que registra la operación **sin modificarla**. La regla es una sola:
la operación no conoce a Finanzas y las áreas operativas no manejan costos; Finanzas
«bebe» de lo que ya funciona.

Este documento cubre la base propia de Finanzas, la **captura de hechos valorizables**, el
**tarifario** y la **prefactura en paralelo**, que valoriza los hechos con el tarifario.

## Cómo se separa de la operación

| Pieza | Dónde | Regla |
| --- | --- | --- |
| Base de datos | Conexión `finanzas` (por omisión, `<DB_DATABASE>_finanzas` en el mismo servidor) | Solo Finanzas escribe aquí. Tablas con prefijo `fin_`. |
| Lectura de la operación | Conexión `operacion_lectura` | Sesión `READ ONLY`: la base rechaza cualquier escritura. |
| Código | `app/Finanzas` | Nada fuera de `app/Finanzas` lo referencia. |
| Rutas | `routes/finanzas.php` (`/api/finanzas/...`) | Leen la operación y escriben solo en la base de Finanzas. |
| Oficina | Administración → Finanzas → *Hechos valorizables*, *Tarifario* y *Prefactura* (con su cierre mensual) | Módulo de acceso `finanzas.prefactura`. |

Las tablas de Finanzas no tienen llaves foráneas hacia la operación: guardan el
identificador del registro operativo y una copia de lo leído (cliente, temporada,
referencia, medidas), de modo que un cambio posterior en la operación no altera lo ya
capturado sin dejar rastro.

`tests/Feature/Architecture/FinanzasArchitectureTest.php` vigila estas reglas: la
operación no importa nada de Finanzas, los modelos de Finanzas viven en su base, Finanzas
consulta la operación solo por `LectorOperacion`, las fuentes no escriben y ninguna
migración cruza llaves entre ambas bases. `FinanzasSoloLeeLaOperacionTest` comprueba que
una captura solo ejecuta `SELECT` sobre la base operativa y que la conexión de lectura
rechaza escrituras.

## Hechos valorizables

Un hecho es algo que la operación registró y que la planta puede cobrar. Cada hecho tiene
cliente, fecha operacional (hora de planta), una referencia legible y sus medidas.

| Fuente | Cuándo es un hecho | Fecha | Medidas | Cliente |
| --- | --- | --- | --- | --- |
| Recepciones en romana | Recepción cerrada | Ingreso del camión | kilos netos, envases declarados | Cliente de la recepción |
| Pallets validados | Validación PT aceptada y aprobada, con folio | Hora de la PDA | bultos, pallets, saldos, cajas | Cliente de Validación vinculado al cliente global |
| Recepción de fruta embalada | Recepción aceptada | Llegada | recepciones, bultos, pallets, saldos, cajas | Cliente de la recepción |
| Prefrío | Folio aprobado en un proceso | Fin del proceso | bultos, pallets, saldos, cajas | Cliente del folio |
| Estadía en cámara | Folio en custodia en cámaras de producto | Ingreso → salida | bultos, pallets, saldos, cajas | Cliente del folio |
| Repaletizajes | Repaletizaje confirmado | Confirmación | operaciones, folios de origen y resultantes, cajas | Cliente del folio resultante |
| Pallets despachados | Folio despachado en una carga | Cierre del despacho | bultos, pallets, saldos, cajas | Cliente del folio |

El cliente de un folio sale de su composición por CSG (`trazabilidad_folio_origenes`); si
no está vinculada, del nombre de la exportadora, comparado con los clientes globales y con
los clientes de Validación sin distinguir mayúsculas, tildes ni espacios. Un hecho sin
cliente queda marcado y se vuelve a leer en cada captura: al vincular el cliente en los
maestros, el hecho se completa solo.

### Versiones

Un hecho nunca se edita. Si la operación lo corrige, la captura crea una versión nueva y la
anterior deja de ser vigente; si la operación lo anula o deja de cumplir la condición (una
recepción reabierta, un prefrío que pasa a reproceso), la nueva versión queda **anulada**.
El historial completo se ve en la oficina con el botón de versión de cada fila.

| Motivo | Significado |
| --- | --- |
| Captura | Primera vez que Finanzas ve el registro. |
| Cambió en la operación | Corrección, cierre de una estadía, cambio de cliente. |
| Anulado en la operación | La operación lo anuló o dejó de ser un hecho. |
| Ya no existe en la operación | El registro se borró (por ejemplo, un reinicio de datos de prueba). |

Un registro anulado antes de la primera captura no deja hechos.

### Estadía en cámara

La estadía mide la **custodia** de un pallet o saldo, no cada movimiento:

- Empieza cuando el folio entra a una cámara de contenido *productos* (cualquier tipo:
  almacenaje, tránsito, preparación o despacho).
- Sigue abierta mientras el folio esté vigente, aunque salga un rato de la cámara (un
  reordenamiento, el andén). Cada salida y regreso suma una interrupción; si el folio está
  fuera de cámara al leer, la fila lo indica.
- Termina con el despacho (hora de cierre de la carga), el consumo en un repaletizaje o la
  anulación de su validación.
- Si el folio quedó en un estado terminal sin un evento con hora (por ejemplo, regularizado
  al cerrar la temporada), la salida es la última salida de cámara registrada y se marca
  como **estimada**.
- Un folio nacido en un repaletizaje empieza su estadía en el repaletizaje si heredó la
  ubicación de un origen en cámara; si no, cuando lo ubican. Si ese repaletizaje se anula,
  su estadía queda anulada y la de los orígenes vuelve a quedar abierta.

Las horas son las del dispositivo (una PDA puede sincronizar después), en el orden en que el
servidor aplicó los movimientos. Los días cobrables los calcula la prefactura con las
reglas del tarifario (días libres, mínimo, corte), no la captura.

## Captura

Las cajas y el tipo de bulto del prefrío corresponden al momento del servicio. Se
reconstruyen desde el antes del primer repaletizaje confirmado posterior, incluso si la
primera captura financiera ocurre después de varios repaletizajes. Los anulados no
participan en esa reconstrucción.

La estadía mantiene una sola custodia cuando un origen aporta cajas y conserva un saldo.
Sus medidas principales describen el bulto al ingreso; `datos.tramos` guarda las cantidades
y el tipo de bulto de cada intervalo `[desde_at, hasta_at)`, con la referencia al
repaletizaje que inicia cada cambio. El último término es nulo mientras la custodia esté
abierta. También se consideran los aumentos de un folio conservado. Los cambios simultáneos
no generan tramos de duración cero. Oficina muestra estos tramos en el historial.

Al desplegar la corrección histórica, la migración deja pendientes las conciliaciones de
Prefrío y Estadía. La próxima captura programada reconstruye ambas fuentes y anula las
estadías duplicadas anteriores, conservando sus versiones. Se puede adelantar con
`php artisan finanzas:capturar-hechos --conciliar`; repetirlo sin cambios no agrega versiones.

- Corre cada 10 minutos (`finanzas:capturar-hechos --programada` en `routes/console.php`)
  y también a pedido con **Leer ahora** (requiere rol Administrador con el módulo).
- Cada fuente lee lo que cambió desde su última marca, repasando 15 minutos hacia atrás.
  Releer un registro sin cambios no crea versiones.
- Cada fuente tiene una conciliación **completa** cada 24 horas: recorre toda la operación, recoge lo que una
  lectura incremental no alcanzó a ver y anula como *Ya no existe en la operación* lo que
  ya no está.
- Si una fuente falla, las demás se capturan igual y la lectura queda *con errores*; la
  fuente que falló se vuelve a leer en la próxima. Su conciliación solo se marca exitosa
  después de terminar la lectura y anular los hechos desaparecidos. Una captura parcial
  no pospone las conciliaciones de las fuentes que no leyó.
- Nunca corren dos capturas a la vez.

Comando manual:

```bash
php artisan finanzas:capturar-hechos                 # todas las fuentes
php artisan finanzas:capturar-hechos --fuente=estadia
php artisan finanzas:capturar-hechos --conciliar     # lectura completa
```

Variables de entorno (todas opcionales):

| Variable | Por omisión | Uso |
| --- | --- | --- |
| `FINANZAS_DB_DATABASE` | `<DB_DATABASE>_finanzas` | Base de Finanzas. |
| `FINANZAS_DB_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD` | Los de `DB_*` | Servidor y usuario de la base de Finanzas. |
| `FINANZAS_LECTURA_DB_USERNAME`, `_PASSWORD` | Los de `DB_*` | Usuario con que Finanzas lee la operación. |
| `FINANZAS_CAPTURA_HABILITADA` | `true` | Pausa la captura programada (la manual sigue disponible). |
| `FINANZAS_CAPTURA_MARGEN_MINUTOS` | `15` | Repaso hacia atrás de cada lectura. |

## Tarifario

Precios por **servicio** y **unidad de cobro**, para un cliente o como **tarifa general**,
con vigencia. Vive en la base de Finanzas (`fin_monedas`, `fin_tarifas` y
`fin_tarifas_eventos`): definir un precio no modifica la operación. Oficina:
Administración → Finanzas → *Tarifario*.

| Concepto | Regla |
| --- | --- |
| Servicio | Concepto comercial respaldado por una fuente de hechos. Se conservan los siete conceptos base; Oficina permite crear otros sobre esos procesos. |
| Unidad de cobro | Una medida de esa fuente: kilo neto, envase, recepción, bulto, pallet, saldo, caja, repaletizaje o folio. En la estadía es por día («por pallet y día»). Cada cliente puede tener su propia unidad. |
| Precio | Decimal exacto, hasta 12 enteros y 6 decimales, en una moneda configurada. |
| Vigencia | Desde y, opcionalmente, hasta: fechas de planta, ambas inclusivas. Un precio no pertenece a una temporada: un contrato de temporada se carga con las fechas de la temporada. |

- **Precio propio o tarifa general.** En un día y un servicio, un cliente con algún precio
  propio usa solo los suyos; si no tiene, usa la tarifa general de ese servicio. Si la
  tarifa general cobra el almacenaje por bulto y día y un cliente tiene precio propio por
  caja y día, a ese cliente se le cobra solo por caja (`ResolutorTarifas`).
- **Sin cruces.** Los precios de un mismo cliente (o de la tarifa general), servicio y
  unidad no se superponen.
- **Sin doble cobro.** En un servicio no conviven el cobro por bulto y el cobro por pallet
  o saldo, porque un bulto es un pallet o un saldo. Pallet y saldo juntos sí: se cobran por
  separado.
- **Reemplazo.** Al crear un precio se puede terminar el día anterior el que regía: el de
  la misma unidad o, al pasar de bulto a pallet y saldo, el de bulto. Un precio nuevo con
  término no corta lo que el anterior cubría después: en ese caso se informa el cruce.
- **Historial.** Crear, editar, cerrar al reemplazar y anular quedan en
  `fin_tarifas_eventos`, con usuario y fecha. Un precio no se borra: se anula con motivo y
  deja de cobrarse. El cliente, el servicio y la unidad no se editan: se anula y se crea
  otro precio.
- **Monedas configurables**: código, nombre, símbolo, decimales de los montos y si está
  activa. No se precargan, porque los datos de Finanzas que el registro de migraciones no
  conoce detienen `migrate` (ver *Instalación en el servidor*); la oficina ofrece CLP, UF y
  USD para agregarlas con un clic. Una moneda inactiva conserva sus precios, pero no se
  ofrece para precios nuevos.
- **Almacenaje**: se cobra por día o fracción (cada día calendario de planta en que el
  bulto estuvo en cámara, aunque sea una parte) y sin días libres. Lo aplica la prefactura,
  día a día y según los tramos de la estadía.
- Mientras la prefactura no tenga cierre, editar un precio cambia la valorización de los
  meses en que rige.

Lo consulta quien ve Finanzas (`consultar-finanzas`); lo edita solo un Administrador con el
módulo (`gestionar-tarifas-finanzas`).

| Método | Ruta (`/api/finanzas`) | Uso |
| --- | --- | --- |
| GET | `/tarifas?cliente=&servicio=&estado=` | Precios, resumen, servicios, monedas y clientes. `cliente=general` filtra la tarifa general; `estado`: `actuales` (por omisión), `vigente`, `futura`, `vencida`, `anulada` o `todas`. |
| GET | `/tarifas/efectiva?cliente=&fecha=` | Lo que se cobra a un cliente (o con la tarifa general) en cada servicio, un día. |
| GET | `/tarifas/{id}` | Precio con su historial. |
| POST | `/tarifas` | Crea un precio; `cerrar_anterior` reemplaza al que regía. |
| PUT | `/tarifas/{id}` | Edita moneda, precio, vigencia y nota. |
| POST | `/tarifas/{id}/anular` | Anula con motivo. |
| POST / PUT | `/monedas`, `/monedas/{codigo}` | Crea o edita una moneda. |

### Conceptos de servicio

En *Tarifario → Conceptos de servicio*, Administración crea un nombre comercial, lo asocia
al proceso que ya captura Finanzas y define fechas de aplicación inclusivas. Después asigna
un precio en *Precios*, con la unidad disponible en ese proceso, moneda y cliente o tarifa
general. Crear un concepto no duplica el hecho: varias líneas conservan el mismo identificador
y versión de respaldo. La captura y la operación no se modifican.

- **Reemplaza al base**: el concepto original no se valoriza en los hechos y fechas que
  coinciden. No pueden existir dos reemplazos del mismo proceso con fechas y días cruzados;
  las altas se serializan con un candado. Fuera de esos rangos vuelve a aplicar el base.
- **Adicional**: suma una prestación al base (y a los demás conceptos adicionales que
  correspondan). Esta elección es explícita; no se presupone que todos los nombres de una
  lista de tarifas son servicios efectivamente prestados.
- **Sin precio**: el concepto queda pendiente; un reemplazo sin precio no recupera el precio
  del base silenciosamente. El precio propio o general se resuelve por concepto.
- **Días de estadía**: desde/hasta son ordinales inclusivos desde el inicio de **esa custodia
  del folio**, contando el ingreso como día 1 en hora de planta; no se reinician al cambiar
  de mes y respetan los tramos históricos de cantidades. Un folio nuevo de repaletizaje inicia
  otra custodia y no cobra el día de su creación, como antes. Esta regla no representa edad
  de cosecha ni antigüedad heredada de otros folios. No se ofrece en procesos puntuales.
- **Definición inmutable**: nombre, fuente y reglas no se editan ni borran. Para cambiarlos
  se finaliza el concepto y se crea otro con su vigencia. Se puede adelantar su término desde
  hoy, con motivo; no extenderlo ni finalizarlo antes de que comience. Historial con usuario,
  fecha y definición en `fin_servicios_eventos`; conceptos en `fin_servicios`. Un concepto
  finalizado permite asignar un precio histórico desde su fila para completar pendientes.
- **Cierres**: no se reescriben. Un concepto creado con fechas anteriores puede cambiar la
  comparación del mes cerrado; crear/finalizar invalida esa revisión, incluso sin tocar un
  precio. Las diferencias siguen el circuito de revisión y decisión existente.

Ejemplo configurable (no se precarga): *Frío normal* reemplaza Estadía en los días 1–15;
*Permanencia adicional* la reemplaza desde el día 16. Otra opción es conservar la tarifa base
y crear solo un recargo adicional desde el día 16. La elección depende del contrato.

Solo se ofrecen las siete fuentes con captura implementada. **SAG/USDA, adhesivos, sellado,
tratamientos, arriendo de bins y servicios manuales no se crean como capturas ficticias**:
requieren su propio registro de ejecución, cantidades y adaptador financiero. Tampoco se
incluyen cobros por mes, paquetes, mínimos, horas fuera de jornada ni filtros comerciales por
tipo de vehículo, especie o condición de fruta. La lista de referencia no incorpora importes.

API adicional (`/api/finanzas`):

| Método | Ruta | Uso |
| --- | --- | --- |
| POST | `/servicios` | Crea definición: nombre, descripción, fuente, aplicación (`adicional` o `reemplaza_base`), vigencias y `dia_desde`/`dia_hasta`. |
| POST | `/servicios/{clave}/finalizar` | Adelanta término con `vigente_hasta` y `motivo`, desde hoy. |
| GET | `/servicios/{clave}/historial` | Definición y eventos; consulta con el permiso de Finanzas. |

`GET /tarifas` incluye conceptos y procesos disponibles; la tarifa efectiva muestra precios
solo dentro de las fechas del concepto. El catálogo inicial sigue siendo virtual: la migración
no cambia claves, precios, hechos ni cierres existentes, ni precarga tarifas de referencia.

## Prefactura

Valoriza los hechos vigentes de un mes con el tarifario, por cliente. Un mes abierto se
calcula al consultarlo; uno cerrado se lee tal como quedó (ver *Cierre mensual*). No emite
documentos y no reemplaza la facturación: sirve para compararla. Oficina: Administración →
Finanzas → *Prefactura*, con descarga a Excel (una fila por línea, por ajuste y por
pendiente).

Reglas (`CalculadoraPrefactura`):

- **Hechos puntuales** (recepción, validación, fruta embalada, prefrío, repaletizaje y
  despacho): se cobran con los precios que rigen su fecha operacional. Si el cliente tiene
  precio propio en ese servicio ese día, se usan los suyos; si no, la tarifa general.
- **Almacenaje por día o fracción, sin días libres**: cuenta cada día calendario de planta
  en que el bulto estuvo en cámara, aunque sea una parte. Una salida justo a medianoche no
  cobra el día que empieza en ese instante.
- **Cada día se cobra con lo que había al empezar el día**; el primer día, con lo del
  ingreso. Si un pallet pasa a saldo a mitad del día, ese día se cobra como pallet y desde el
  siguiente como saldo.
- **Repaletizaje**: un folio que nace en un repaletizaje y hereda la custodia de sus orígenes
  empieza a pagar al día siguiente, porque ese día ya lo pagan los orígenes.
- **Un folio no paga dos veces el mismo día**, aunque salga de la cámara y vuelva a entrar.
- **Cambio de precio a mitad de mes**: cada precio es una línea distinta.
- **Montos**: cantidad por precio, redondeado por línea a los decimales de la moneda (la
  mitad hacia arriba). Los totales suman las líneas de cada moneda; no se convierte entre
  monedas.
- **Pendientes**: un hecho sin cliente vinculado, o sin precio ese día, no se valoriza. La
  oficina y el Excel los muestran para completarlos en los maestros o en el tarifario.
- **Mes en curso**: se valoriza hasta hoy (a la fecha); las estadías abiertas cuentan hasta
  hoy. Lo anulado no se cobra.

Con 20.000 estadías, 20.000 validaciones y 10.000 despachos, un mes completo se calcula en
cerca de 2 segundos.

## Cierre mensual

Cerrar un mes congela lo que se cobra: las líneas por cliente, lo que aportó cada hecho a cada
línea, quién cerró, cuándo y con qué lectura de la operación. Desde ese momento el mes no
cambia, aunque cambien la operación o el tarifario.

| Regla | Detalle |
| --- | --- |
| Qué se cierra | Un mes terminado, con todos sus clientes. El mes en curso no se cierra. |
| Orden | El primer cierre marca el inicio; desde ahí se cierra el mes siguiente al último cerrado. |
| Pendientes | Si quedan hechos sin precio o sin cliente, el cierre los lista y pide confirmarlos: no se cobran en ese cierre. |
| Lecturas | No se cierra mientras una lectura de la operación registra hechos: el último hecho registrado separa lo cerrado de lo posterior. |
| Reapertura | Solo el último mes cerrado, por un Administrador y con motivo. La versión queda reabierta; al cerrar de nuevo se guarda otra versión. |
| Permisos | Cierra y decide diferencias quien administra el tarifario. Consultar es de quien ve Finanzas. |

### Diferencias después del cierre

Lo que cambia después de cerrar no toca el mes cerrado: aparece como **diferencia**. Para
encontrarlas, el mes se recalcula con los hechos y precios de hoy y se compara, línea por
línea (cliente, precio y moneda), con lo cobrado: lo congelado más lo ya decidido. Cada
diferencia trae cantidad, días y monto, y los hechos que la explican:

| Hecho | Qué pasó |
| --- | --- |
| Nuevo | Llegó después del cierre, o ahora se cobra con este precio. |
| Corregido | Cambió su cantidad o sus días (por ejemplo, la estadía salió otro día). |
| Retirado | Dejó de cobrarse en esa línea: se anuló, cambió de cliente o ahora se cobra con otro precio. |

Un cambio de precio que afecta un mes cerrado también es una diferencia, sin hechos: muestra el
precio cobrado y el de hoy.

Las diferencias se deciden **una por una**:

- **Aceptar**: se cobra (o se abona, si es negativa) en el **primer mes abierto**, como un
  ajuste del cliente con el mes de origen. Se puede deshacer mientras ese mes siga abierto; al
  cerrarlo, el ajuste queda en su cierre.
- **Descartar**: exige motivo y queda registrada (por ejemplo, ya se cobró con una nota de
  débito).

Antes de registrar una decisión, la diferencia se recalcula: si cambió desde que se revisó,
se pide revisarla de nuevo. Al reabrir un mes, sus decisiones quedan sin efecto, porque al
cerrarlo otra vez el mes ya incluye esos cambios.

La oficina avisa qué meses cerrados tienen diferencias por revisar. Para no recalcular un mes
en el que nada cambió, cada cierre guarda su última revisión y solo se recalcula si llegaron
versiones de hechos de ese mes, cambió algún precio o moneda, o se tomó una decisión.

Con 32.000 hechos en el mes, cerrar tarda cerca de 3 segundos y recalcular las diferencias,
1,5 segundos. Sin cambios, la revisión responde en milisegundos.

| Método | Ruta (`/api/finanzas`) | Uso |
| --- | --- | --- |
| GET | `/prefactura?mes=AAAA-MM` | El mes por cliente: líneas, ajustes, totales por moneda, pendientes y estado del cierre. |
| GET | `/prefactura/detalle?mes=&cliente=&tarifa=` | Los hechos que forman las líneas de un cliente; con `tarifa`, solo los de esa línea. En un mes cerrado, los congelados. |
| GET | `/prefactura/exportar?mes=` | Excel del mes. |
| POST | `/prefactura/cierres` | Cierra el mes (`mes`, `confirmar_pendientes`). |
| POST | `/prefactura/cierres/{mes}/reabrir` | Reabre el último mes cerrado, con `motivo`. |
| GET | `/prefactura/diferencias?mes=` | Diferencias pendientes de un mes cerrado y decisiones tomadas. |
| GET | `/prefactura/diferencias/resumen` | Meses cerrados con diferencias por revisar. |
| POST | `/prefactura/diferencias` | Acepta o descarta una diferencia, con los valores revisados. |
| POST | `/prefactura/diferencias/{id}/anular` | Deshace una decisión, con `motivo`. |

## Instalación en el servidor

1. `php artisan migrate --force` crea la base de Finanzas si el usuario de la conexión
   tiene permiso `CREATE`. Si no lo tiene, cree la base antes, con el mismo juego de
   caracteres de la operativa:

   ```sql
   CREATE DATABASE estiba_wms_finanzas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Recomendado: un usuario propio de solo lectura para que Finanzas consulte la operación
   (la sesión ya es de solo lectura, pero así la protección también está en los permisos).
   Nombres de ejemplo:

   ```sql
   CREATE USER 'finanzas_lectura'@'localhost' IDENTIFIED BY '<clave>';
   GRANT SELECT ON estiba_wms.* TO 'finanzas_lectura'@'localhost';
   ```

   y en `.env`: `FINANZAS_LECTURA_DB_USERNAME` y `FINANZAS_LECTURA_DB_PASSWORD`.

3. Los respaldos deben incluir **ambas bases**. El registro de migraciones vive en la base
   operativa: restaure las dos del mismo momento.

4. El perfil inicial de Administrador recibe el módulo *Hechos y prefactura*. Para otros
   usuarios, asígnelo desde Accesos (rol base Administrador para capturar a pedido y editar
   el tarifario; un perfil de solo consulta puede ver los hechos y los precios).

5. Antes de cargar precios, agregue las monedas en *Tarifario → Monedas*.

`php artisan migrate:fresh` confirmado sobre la conexión operativa reinicia también las
tablas de Finanzas, cuando su registro de migraciones ya está vacío. Cancelar el comando
no borra ninguna tabla financiera. Un `db:wipe` suelto conserva Finanzas; el siguiente
`migrate` se detiene si quedaron datos financieros sin su registro de migraciones.

La migración correctiva recrea la estructura si el proveedor anterior ya borró todas las
tablas financieras y sus migraciones siguen registradas. No recupera los datos perdidos:
para ellos se necesita el respaldo. Si solo falta parte del esquema, se detiene y conserva
las tablas restantes para su recuperación.

`FINANZAS_DB_URL` determina la base efectiva, también al crearla. Si la operación usa
`DB_URL`, Finanzas hereda el servidor y las credenciales de esa URL; las credenciales
`FINANZAS_LECTURA_DB_*` se aplican después y prevalecen para la conexión de solo lectura.

El reinicio controlado de datos
de prueba (Accesos y temporadas) no toca Finanzas: en la siguiente lectura completa, los
hechos de los registros borrados quedan como *Ya no existe en la operación*.

## Próximos pasos

1. Ajustes manuales por cliente (descuentos o cargos con glosa), que se congelan con el cierre.
2. Comparación con la facturación emitida: diferencias por cliente y servicio entre la
   prefactura cerrada y lo que efectivamente se facturó.
