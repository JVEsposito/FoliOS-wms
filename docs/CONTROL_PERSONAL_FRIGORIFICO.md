# Control interno de personal · Frigorífico

Oficina → Frigorífico → **Control de personal** (`/oficina/frigorifico/control-personal`).

Es el registro propio del supervisor de Frigorífico sobre las horas de su gente. Sirve para una sola cosa:
cuando a fin de mes un trabajador pide revisar sus horas, contrastar **lo que registró el supervisor** con
**lo que registró RRHH**.

## Separación con RRHH

Por decisión de negocio, este control **no conversa con RRHH en ningún sentido**:

- No lee ni escribe datos de RRHH, Finanzas ni COMEX, ni sus bases (`rh_`, `fin_`, `cx_`).
- RRHH, Finanzas, COMEX y Gestión no leen sus tablas (`frigorifico_personas`, `frigorifico_jornadas`,
  `frigorifico_ausencias`, `frigorifico_control_personal_eventos`).
- Las personas del control son independientes de las fichas de RRHH y de los usuarios de FoliOS.
- No liquida remuneraciones ni es registro de asistencia laboral.

`tests/Feature/Architecture/ControlPersonalFrigorificoArchitectureTest.php` falla si cualquiera de los dos
lados empieza a referirse al otro. La comparación se hace afuera del sistema, con la ficha mensual en la mano.

## Acceso

Módulo `frigorifico.control-personal`, solo para los roles Supervisor de frío y Administrador con perfil activo.
Un operador o un perfil de consulta no ve fichas, jornadas, ausencias, exportaciones ni PDF, aunque se le asigne
el módulo.

## Personal

Ficha propia del control: nombre, identificador o RUT opcional, sector, función y **jornada base neta** (sin
colación). Cambiar la base afecta solo a jornadas nuevas; las registradas conservan la suya. Una persona inactiva
conserva su historial y no admite registros nuevos.

## Jornadas

Ingreso, salida (puede quedar pendiente), colación, jornada base del día, sector y observación, en hora de planta
(`America/Santiago`). No se aceptan horas futuras, permanencias de más de 36 horas ni jornadas superpuestas de la
misma persona.

- **Horas extra internas** = permanencia − colación − jornada base del día, con mínimo cero. Criterio diario.
- Una jornada que cruza medianoche se imputa completa al **día y mes de ingreso**.
- Pendientes (sin salida) y anuladas no suman horas.
- Cerrar, corregir o anular exige motivo y versión vigente (otro supervisor pudo cambiarla) y queda en el historial.

## Ausencias

Días **completos** sin jornada: falta, permiso, licencia, libre o vacaciones, por un día o por un rango de hasta
92 días, con observación opcional.

- No se puede marcar un día en que la persona tiene una jornada vigente con ingreso ese día, ni registrar una
  jornada con ingreso en un día marcado como ausencia. Una jornada nocturna que termina en un día libre sí se admite.
- Dos ausencias vigentes de la misma persona no se superponen.
- Una falta no se registra por anticipado; vacaciones, permisos, licencias y libres pueden programarse.
- Corregir y anular funcionan igual que en las jornadas: motivo, versión e historial propio.
- El resumen cuenta los días de cada ausencia que caen dentro del mes consultado. Un rango que cruza meses se
  reparte entre ambos.
- Las ausencias no guardan sector: el filtro por sector usa el sector actual de la persona.

## Ficha mensual PDF

La herramienta para la conversación de fin de mes. Una hoja A4 por persona (más si el mes tiene muchas notas) con:

- una fila por cada día del mes, fines de semana sombreados: ingreso, salida (`+1` si termina al día siguiente),
  colación, base, trabajado, extra y observación; ausencias con su tipo; días sin nada como «Sin registro»;
- totales del mes: jornadas cerradas, salidas pendientes, trabajado, horas extra y días por tipo de ausencia;
- el criterio de cálculo, y un espacio para anotar las diferencias encontradas y firmar supervisor y trabajador.

No incluye jornadas ni ausencias anuladas. Se descarga desde **Ficha PDF** en cada fila del resumen, o con
**Fichas PDF** para todas las personas con registros en el mes (respeta los filtros de persona y sector).

## Exportaciones

- **Excel resumen**: una fila por persona con horas y días por tipo de ausencia.
- **Excel detalle**: una fila por jornada, incluidas las anuladas sin horas.
- **Excel ausencias**: una fila por ausencia que toca el mes, con días en el mes y del periodo.

## API

Todas bajo `/api/frigorifico/control-personal`, con `Cache-Control: no-store, private`.

```http
GET  /?mes=YYYY-MM&persona_id=&sector=                     resumen, jornadas y ausencias del mes
GET  /exportar?mes=YYYY-MM&vista=resumen|detalle|ausencias  Excel
GET  /ficha?mes=YYYY-MM&persona_id=&sector=                 ficha mensual PDF (422 si no hay registros)
POST /personas · PUT /personas/{id}
POST /jornadas · PUT /jornadas/{id} · POST /jornadas/{id}/anular · GET /jornadas/{id}/historial
POST /ausencias · PUT /ausencias/{id} · POST /ausencias/{id}/anular · GET /ausencias/{id}/historial
```

Ausencia: `persona_id`, `tipo` (`falta`, `permiso`, `licencia`, `libre`, `vacaciones`), `desde` y `hasta`
(`YYYY-MM-DD`), `observacion`; al corregir, además `version` y `motivo`.
