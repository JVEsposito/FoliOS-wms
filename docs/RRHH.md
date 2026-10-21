# Recursos humanos y asistencia operativa

## Alcance inicial

RRHH mantiene personas **independientes de los usuarios de FoliOS**, empleador, cargo, área y CECO. Programa asignaciones por persona, horario y cliente; registra asistencia propia o importada y permite revisar incidencias y corregir con motivo. La sección Remuneraciones incorpora cargos, conceptos, condiciones, asignaciones mensuales y cálculo del valor de horas extra verificadas; prepara novedades para liquidación, sin determinar automáticamente qué minutos califican como extraordinarios ni sustituir contratos de trabajo.

- Oficina → Administración → **Personal y dotación** (`/oficina/rrhh`): fichas, programación, asistencia, costos internos opcionales e integraciones.
- **Puesto de marcaje** (`/oficina/rrhh/marcaje`): código y PIN de 6–12 números en un puesto con sesión autorizada. La hora la toma el servidor. Funciona en navegador, incluida tablet, sin cambiar APK.
- Permisos separados `rrhh.personal` y `rrhh.marcaje`. Un puesto de marcaje no consulta fichas, RUT, costos, auditoría ni credenciales. Un perfil de consulta no modifica datos. Solo el perfil administrador inicial recibe ambos módulos por migración.

El marcaje propio es **operativo**. No se presenta como registro de asistencia laboral autorizado. La Dirección del Trabajo exige autorización del sistema electrónico para ese uso: [DT, autorización de sistemas](https://www.dt.gob.cl/portal/1626/w3-article-124477.html). Antes de usarlo como registro laboral oficial debe completarse ese proceso; la primera etapa no declara certificación ni implementa todas sus exigencias.

## Datos y despliegue

`php artisan migrate` prepara la base `<DB_DATABASE>_rrhh`; se puede crear previamente si el usuario de aplicación no tiene permiso CREATE. Variables opcionales: `RRHH_DB_URL`, `RRHH_DB_HOST`, `RRHH_DB_PORT`, `RRHH_DB_DATABASE`, `RRHH_DB_USERNAME`, `RRHH_DB_PASSWORD`, `RRHH_DB_SOCKET`. El formato en `.env.example` no contiene valores privados. La URL se interpreta con la misma precedencia que Laravel.

Las tablas `rh_*` no tienen llaves hacia la base operativa. RRHH solo consulta clientes por `LectorOperacion` y la sesión `operacion_lectura`; nunca escribe operación, Finanzas ni Comex. El registro de migraciones y la protección de bases huérfanas usan `App\Gestion`, igual que los otros módulos. Respaldar operación, RRHH y `APP_KEY` de forma coherente; la clave es necesaria para recuperar las credenciales cifradas. No usar `migrate:fresh` o `db:wipe` en bases con datos que se necesiten conservar.

PIN con hash, configuración de fuentes cifrada con `APP_KEY`, campos ocultos en respuestas y auditoría sin secretos. Los valores se introducen en el sistema, no en GitHub. Las fuentes tienen código y formato permanentes; actualizar permite activar/desactivar y cambiar configuración. Omitir configuración conserva la existente; un objeto fusiona solo las claves recibidas y `null` la elimina.

## Programación y cálculo

Cada asignación conserva persona, cliente, cargo, área, CECO y fuente de marcaje del momento. El código personal es permanente. Una asignación dura hasta 24 horas transcurridas, admite turno nocturno y descanso programado, y no puede superponerse con otra no cancelada de la misma persona. Un cambio de estado exige versión y motivo; una cancelada se conserva y no se reabre. Crear otra es una nueva decisión operativa.

Las fechas de API son ISO 8601 con segundos y zona explícita. Se almacenan en UTC. Los filtros por día usan `APP_OPERATIONAL_TIMEZONE`; el formulario de programación informa el huso del equipo usado para introducir las horas. El costo opcional requiere moneda explícita y es una estimación interna por hora, independiente del precio cobrado al cliente.

| Medida | Criterio |
| --- | --- |
| Horas programadas | Duración transcurrida menos descanso programado, incluso si la asignación se cancela |
| Jornada programada | Una asignación; no equivale automáticamente a jornada legal |
| Horas asistidas | Pares entrada/salida recortados al intervalo asignado; los descansos reales requieren salida/entrada |
| Jornada asistida | Una asignación con asistencia válida mayor que cero |

Los cambios de horario estacional se calculan por tiempo real. No se descuenta dos veces un descanso. Sin marcajes, entradas consecutivas, entradas sin salida, salidas huérfanas o marcas simultáneas dejan incidencias. Las horas mostradas con incidencias son provisionales: no habilitan un cargo basado en asistencia. No se inventa una salida al terminar el turno. Un costo asistido solo se ofrece con asistencia válida. La consulta usa marcajes de hasta 24 h antes/después para respaldar pares que cruzan los límites; jornadas externas más largas requieren revisión.

El estado operativo **no elimina cantidades programadas ni obligaciones comerciales**. Un compromiso, reserva de cuadrilla, disponibilidad, mínimo o cancelación puede cobrarse como concepto acordado de Finanzas, con respaldo y aprobación económica explícita. El costo interno no es una tarifa de venta.

## Llevar dotación a Finanzas

Finanzas → Tarifario → Cargos acordados → **Desde dotación RRHH**. Requiere permisos de Finanzas y consulta de RRHH. Se elige concepto manual, asignación, medida, fecha del cobro y respaldo. Hay que confirmar que la unidad del concepto y su precio corresponden a horas persona o jornadas persona; no se deduce esa correspondencia del nombre libre del concepto.

Finanzas lee por `rrhh_lectura`, sesión MySQL de solo lectura y, preferiblemente, un usuario SELECT definido con `RRHH_LECTURA_DB_USERNAME` / `RRHH_LECTURA_DB_PASSWORD`. `FINANZAS_CONEXION_RRHH` permite seleccionar otra conexión de lectura. La URL de RRHH se resuelve antes de aplicar esas credenciales, para que no las reemplace. La lectura de cada propuesta usa una transacción consistente. No escribe RRHH y no consulta fichas ni credenciales: copia referencia de asignación, cantidades, horario, cargo operativo, área y CECO; no copia nombre personal, RUT, PIN ni costo interno.

- **Programación:** horas o jornadas contratadas. No requiere marcajes y se conserva aunque se cancele el turno. La base económica debe ser acuerdo, disponibilidad o compromiso.
- **Asistencia:** horas o jornadas con marcajes completos, sin incidencias y turno terminado. Una cantidad cero no crea un cargo. No calcula horas extra legales.
- Se crea un **borrador**, que se revisa y aprueba expresamente en Finanzas. No hay aprobación ni sondeo automático. La cantidad y el cliente los obtiene el servidor, no el formulario. El precio se resuelve con el tarifario de la fecha elegida.
- La asignación se cobra como una unidad completa: si cruza de mes, aparece en ambas consultas para encontrarla, pero no se fracciona ni duplica automáticamente. La fecha económica la elige el responsable. Los cortes o mínimos distintos se declaran como conceptos/cargos acordados con su respaldo.
- Solo un cargo vigente por asignación y concepto; elegir programación y después asistencia para el mismo concepto no agrega otro cobro. Reintentar el mismo contenido devuelve el borrador existente. Otro concepto puede respaldar un servicio adicional explícito.
- El borrador conserva la copia elegida. Al aprobar, el responsable revisa cantidades y respaldo. Cambios posteriores en la fuente no reescriben cargos ni cierres; la reimportación con cambios se detiene para revisión. Reemplazar exige anular económicamente el cargo anterior y confirmar su ID; crea revisión nueva, conserva las anteriores y vuelve a exigir aprobación.

Los cierres mensuales y sus diferencias usan el mismo circuito de cargos acordados. No se generan ejecuciones operativas ficticias ni se confunde cancelar un turno con cancelar una obligación económica. Bonos, reservas, mínimos, servicios no ejecutados y otros conceptos libres pueden declararse directamente en Finanzas sin una asignación de RRHH.

## Marcaje propio, reintentos y correcciones

`POST /api/rrhh/marcajes`: `operacion_id` UUID, `codigo`, `pin`, `tipo` (`entrada`/`salida`). No acepta hora del dispositivo. Repetir el UUID devuelve el mismo comprobante; cambiar persona o tipo con ese UUID se rechaza. La persona debe estar activa y tener fuente `folios`. El puesto mantiene sesión de usuario con permiso; no es un terminal público anónimo ni ofrece marcaje sin conexión.

`POST /api/rrhh/marcajes/{id}/corregir`: `motivo` y, para reemplazar, `instante` y `tipo`. Sin instante anula. Se conserva la marca original, se vincula el reemplazo con canal `correccion` y se audita al responsable. Una reimportación del original no reactiva lo corregido. No se sobrescriben las horas del proveedor ni se simula que una corrección fue recibida desde él.

## Integraciones y GeoVictoria

1. Crear fuente (`normalizado` o `geovictoria`) y guardar su configuración privada en pantalla.
2. Vincular explícitamente persona con identificador externo. Los identificadores externos se comparan exactamente, incluidas mayúsculas y minúsculas. No se adivina por nombre ni se reasigna un identificador histórico. El vínculo se agrega desde Personal → Vincular fuente.
3. Asignar esa fuente a la ficha personal **antes** de programar las asignaciones que deben usarla. Cada asignación guarda la fuente elegida en ese momento. El sistema propio no mezcla sus marcas con una fuente externa.
4. Recibir lotes por JSON desde Integraciones o `POST /api/rrhh/fuentes/{uuid}/importar`. Hasta 1.000 objetos por solicitud. La importación conserva cantidades aceptadas/repetidas y errores por fila; los registros válidos permanecen aunque otra fila falle. Reenviar solo repite los ya recibidos. Mismo identificador con diferente contenido exige revisión y corrección.

Contrato normalizado:

```json
{"marcajes":[{"id_externo":"registro-estable-1","identificador":"persona-en-proveedor","tipo":"entrada","instante":"2026-11-01T08:00:00-03:00"}]}
```

El adaptador GeoVictoria admite `id_punch`, `UserIdentifier`/`identifier`, `Type` (`Ingreso`/`Salida`) o `type` (`entry`/`exit`) y `Date`. Se exige ID estable como texto y fecha ISO con zona explícita; si el proveedor entrega fecha sin zona, el conector debe normalizarla usando el contrato verificado de la cuenta. No se infiere el huso ni se crea un ID aleatorio para evitar duplicados.

Referencia del formato: [documentación oficial GeoVictoria, noviembre 2020](https://wiki.geovictoria.com/wp-content/uploads/2020/11/Conjunto-de-Endpoints-GV3-unificado.pdf). Es una referencia histórica, **no** prueba del contrato vigente de una cuenta. Esta etapa prepara fuentes, credenciales y recepción, pero **no conecta ni sondea automáticamente GeoVictoria**. Antes del conector HTTP se debe validar Swagger, autenticación, identificador estable, zona y paginación de la cuenta actual. Métodos pendientes que avanzan un cursor al consultarse no deben ejecutarse hasta tener entrega durable y reintento verificados; el importador no consume ningún cursor externo.

Las claves opcionales de configuración son `base_url`, `usuario`, `password`, `api_key`, `api_secret`; no se realizan peticiones a esa dirección en esta etapa. Una importación parcial no se considera conciliación completa ni autoriza avanzar un cursor del proveedor.

## Revisión

La UI ofrece historial de personas, asignaciones y marcajes; Integraciones muestra los últimos 20 lotes y sus errores. Los listados de personas, asignaciones, marcajes, vínculos e historial están paginados. API de lectura con `consultar-rrhh`; escritura con `gestionar-rrhh`; reloj únicamente con `marcar-rrhh`. Se prueban aislamiento de permisos, secretos, reintentos, importación parcial, turnos nocturnos, pausas, cambio de huso, cancelación y corrección sin perder originales.

## Cargos, conceptos y novedades de remuneraciones

La sección **Cargos** permite crear y mantener cargos laborales con código permanente, nombre, descripción, estado y control de versión. Las condiciones de cada persona guardan el cargo y su nombre como antecedente histórico. Cambiar el catálogo no cambia ese antecedente ni los turnos ya programados.

**Remuneraciones** exige además el módulo de acceso `rrhh.remuneraciones`. El perfil inicial de Administrador lo recibe; otros perfiles se asignan desde Accesos. Consulta puede leer y Administración puede gestionar. El puesto de marcaje, Finanzas y un perfil de asistencia no reciben sueldos ni descuentos por tener acceso operativo.

- Conceptos propios: haberes, bonos, asignaciones, descuentos y aportes del empleador. Cálculos de cantidad por valor, porcentaje de una base explícita, u horas extraordinarias de sueldo mensual en régimen general. No se ejecutan fórmulas arbitrarias.
- Cada concepto explicita carácter remuneracional, imponibilidad, tributación, si un haber fijo mensual integra el sueldo para HE, grupo del descuento y fundamento revisado por el responsable. Puede mapear códigos externos y LRE; un código libre no acredita homologación oficial. El nombre «bono» o «asignación» no determina su naturaleza. Para cambiar tratamiento se crea otro código, no se reescribe el anterior.
- Condiciones laborales con cargo, sueldo mensual, horas ordinarias semanales, régimen, tramo IMM, vigencia y respaldo. No sustituyen el contrato ni su registro en REL. No se superponen condiciones de una persona.
- Asignaciones mensuales de conceptos con cantidad, valor y vigencia, distintas de las asignaciones operativas de turnos. Preparan borradores; no generan pagos automáticos. Un pacto mensual admite una sola novedad aprobada por mes. Una corrección anula la anterior y registra otra.
- Las vigencias se pueden acortar con motivo y comparación del término actual. Se impide retirar fechas usadas por novedades aprobadas: primero deben anularse y rectificarse. Las vigencias sucesivas permiten cambios de sueldo/cargo/asignación sin sustituir antecedentes anteriores.
- Novedades: simulación → borrador → aprobación → eventual anulación motivada. UUID de operación para reintentos y cantidad normalizada. Se conservan entrada, resultado, conceptos, condiciones, parámetros y adicionales utilizados. Si cambian los antecedentes de un borrador, la aprobación se detiene; los aprobados conservan su cálculo. No hay borrado ni reapertura de anulados.

Esto prepara antecedentes de la liquidación, **no calcula una liquidación legal completa ni un líquido final**. No se aplican automáticamente AFP, salud, AFC, SIS, reforma previsional, impuesto único, gratificación, semana corrida, licencias, feriados, finiquito o embargos. Sus bases, tasas, topes, regímenes y datos individuales requieren el motor remuneracional validado o la integración de nómina. Un aporte del empleador no se resta al trabajador. Los conceptos no remuneracionales requieren fundamento real: no basta marcar una casilla para eximir pagos.

### Reglas chilenas revisadas el 21-10-2026

Fuentes primarias y alcance implementado:

| Materia | Regla y comportamiento | Fuente |
| --- | --- | --- |
| Jornada general | 44 horas hasta 25-04-2026; 42 desde 26-04-2026; 40 desde 26-04-2028. Las condiciones deben respetar el máximo durante toda su vigencia. Jornadas especiales, excluidos y ciclos art. 22 bis requieren revisión específica; no usan la fórmula general automática. | [DT, Ley 40 horas](https://www.dt.gob.cl/portal/1626/w3-propertyname-2556.html), [Ley 21.561](https://www.bcn.cl/leychile/navegar?idNorma=1191554) |
| Horas extraordinarias | Minutos extraordinarios **verificados**; nunca se deducen automáticamente de las horas recortadas al turno operativo. Su calificación exige revisar jornada/semana completa, permisos y conocimiento del empleador. La semana que cruza meses se debe revisar completa. El módulo calcula el valor una vez determinada la cantidad. | [DT, jornada extraordinaria](https://www.dt.gob.cl/portal/1628/w3-article-60151.html), [DT, semana entre meses](https://www.dt.gob.cl/portal/1628/w3-article-60176.html) |
| Valor de HE | `(sueldo mensual computable × 7 / (30 × horas ordinarias semanales)) × (1 + recargo/100) × minutos/60`. Piso de IMM aplicable; recargo mínimo 50%, superior si se pacta. Incluye adicionales mensuales que tengan carácter de sueldo, no cualquier bono. Decimal exacto, sin factor truncado; se redondea el total de la novedad al peso más cercano. | [DT, cálculo sueldo mensual](https://www.dt.gob.cl/portal/1628/w3-article-95182.html), [Código del Trabajo arts. 32 y 42](https://www.bcn.cl/leychile/navegar?idNorma=207436) |
| Pactos y excesos | Pacto escrito por necesidades temporales, vigencia hasta tres meses. Dos HE diarias en régimen general. La falta de pacto y los excesos generan incidencias que requieren reconocimiento al aprobar; **no se recorta ni se elimina el pago del tiempo trabajado**. Se acumulan HE aprobadas de distintos conceptos de la persona en el día. Compensación con feriado requiere acuerdo y control propio: aquí se calcula pago en dinero. | [Código arts. 31–32](https://www.bcn.cl/leychile/navegar?idNorma=207436), [DT, falta de pacto no elimina pago](https://www.dt.gob.cl/legislacion/1624/w3-article-123644.html) |
| IMM | General $539.000 / menores de 18 y mayores de 65 $402.082 de enero a abril 2026. Desde mayo 2026: $553.553 / $412.938. Registros con fuente y vigencia hasta diciembre 2026; **2027 exige incorporar el reajuste efectivo, no se extrapola**. Jornada parcial hasta 30 horas permite proporcionalidad respecto del máximo legal; una jornada intermedia superior a 30 exige IMM íntegro. | [Ley 21.751](https://www.bcn.cl/leychile/navegar?idNorma=1214530), [Ley 21.830](https://www.bcn.cl/leychile/navegar?idNorma=1225354), [DT, proporcionalidad](https://www.dt.gob.cl/portal/1628/w3-article-60136.html) |
| Descuentos | Todo descuento requiere referencia al acuerdo escrito, ley u orden judicial. Facultativos generales hasta 15%; créditos de vivienda/educación en condiciones art. 58 hasta 30%, con pago directo respaldado; cooperativas hasta 25% para su régimen y junto a los generales, máximo conjunto facultativo 45%. Se acumulan por persona y mes con la **misma remuneración bruta completa documentada**, sin usar como base la suma parcial de novedades. No se permite aprobar excediendo topes. Obligatorios y judiciales no se someten automáticamente a estos topes: sus reglas deben revisarse aparte. | [Código art. 58](https://www.bcn.cl/leychile/navegar?idNorma=207436), [DT ORD 262/4](https://www.dt.gob.cl/legislacion/1624/w3-article-100110.html), [DT cooperativas](https://www.dt.gob.cl/legislacion/1624/w3-article-102449.html) |
| Haberes no remuneracionales | Colación, movilización y reintegros se clasifican según naturaleza, respaldo y razonabilidad, sin exención automática por el nombre. | [Código arts. 41–42](https://www.bcn.cl/leychile/navegar?idNorma=207436), [SII, asignaciones e impuesto](https://www.sii.cl/preguntas_frecuentes/declaracion_renta/001_140_3746.htm) |
| LRE | La DT publica carga CSV/TXT con columnas exactas y formulario de Mi DT. Los códigos preparados aquí no constituyen declaración, envío oficial ni sustituyen los datos obligatorios de una liquidación completa. | [DT, Libro de Remuneraciones Electrónico](https://www.dt.gob.cl/portal/1626/w3-article-119843.html) |

Las autorizaciones escritas, legalidad de descuentos, régimen de jornada y tratamiento tributario deben verificarse con los antecedentes del trabajador. No se crean descuentos automáticos por pérdidas, daños, multas o atrasos. No se presume un recargo nocturno general: puede configurarse un beneficio contractual si corresponde.

### Separación de Finanzas

Una obligación laboral con el trabajador y un servicio comercial vendido al cliente son conceptos distintos, con permisos, acuerdos y aprobación propios. Esta ampliación no copia sueldos ni descuentos a Finanzas y no convierte automáticamente remuneraciones en cobros. Se mantiene el flujo de cargos comerciales acordados y su posibilidad de cobro independiente de la ejecución.
