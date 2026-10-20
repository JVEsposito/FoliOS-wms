# Recursos humanos y asistencia operativa

## Alcance inicial

RRHH mantiene personas **independientes de los usuarios de FoliOS**, empleador, cargo, área y CECO. Programa asignaciones por persona, horario y cliente; registra asistencia propia o importada y permite revisar incidencias y corregir con motivo. No liquida sueldos, no determina horas extra legales ni sustituye contratos de trabajo.

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
