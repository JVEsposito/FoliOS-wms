# Despacho PT: carguío, cambios, reingreso y retorno

El flujo normal de despacho (publicar → separar → enviar al andén → cerrar) ya
estaba cubierto. Este documento describe lo que pasa en el andén y las
excepciones comerciales que antes no tenían registro.

## 1. Carguío al camión

«En andén» y «cargado» son estados distintos del pallet dentro de la carga:

| Estado de la asignación | Significado | Reserva |
| --- | --- | --- |
| `pendiente` / `con_incidencia` | El pallet sigue en cámara | Sí |
| `en_anden` | Salió de la cámara y espera en el andén | Sí |
| `cargado` | El camarero lo leyó al subirlo al camión o contenedor | Sí |
| `despachado` | La salida del camión quedó confirmada | No |

- **Registrar cargado** (`POST /api/cargas/{carga}/cargados`): la tablet envía el
  número de folio leído. El servidor busca el pallet dentro de la carga y:
  - rechaza el pallet de otra carga nombrando su código («pertenece a la carga
    CAR-000123, no a CAR-000120»);
  - rechaza un pallet que todavía no sale de la cámara;
  - informa una segunda lectura del mismo pallet sin cambiar nada.
- **Bajar del camión** (`POST /api/cargas/asignaciones/{asignacion}/deshacer-cargado`):
  exige motivo y devuelve el pallet a `en_anden`. Queda el evento
  `folio_descargado` con el motivo y la hora original de carga.
- Ambas operaciones usan `operacion_id`: reenviar la misma lectura no duplica el
  evento. Bloqueo: carga → asignación, el mismo orden del envío al andén y del
  cierre, por lo que una lectura y un cierre simultáneos se serializan.
- Lo hace quien puede enviar al andén (camarero de productos con el módulo de
  cargas).

### Cierre

- El cierre convierte `en_anden` y `cargado` en `despachado` y guarda en el
  evento `cierre_despacho` cuántos pallets se leyeron y cuáles no.
- `DESPACHO_CIERRE_EXIGE_CARGADOS=true` hace obligatorio que todos estén
  `cargado` antes de cerrar. Queda en `false` hasta que todas las tablets del
  andén tengan la APK nueva.
- La salida física no puede ser anterior al último pallet cargado.

### Visibilidad

- La cola de la tablet (`/api/cargas/pendientes`) y la ruta de extracción
  incluyen las cargas `despachada` (todo en andén): la tablet las necesita para
  registrar lo que sube al camión hasta el cierre.
- El progreso (`progreso`) mantiene `en_anden` como «salió de la cámara»
  (incluye los cargados) y suma `cargados` y `por_cargar`.

## 2. Cancelar, quitar y cambiar pallets antes del cierre

Decisiones comerciales que pueden llegar en cualquier etapa, incluso con el
camión en el andén. Las hace «despachador hacia arriba» (Despachador,
Supervisor de frío y Administrador: permiso `gestionar-cargas`) y siempre
exigen motivo una vez publicada la carga.

| Dónde está el pallet | Qué pasa al cancelar, quitar o cambiar |
| --- | --- |
| En cámara (`pendiente` / `con_incidencia`) | Se libera la reserva y vuelve a ofrecerse |
| Fuera de cámara (`en_anden` / `cargado`) | Se libera la reserva y queda **pendiente de reingreso** |

- **Cancelar** (`POST /api/cargas/{carga}/cancelar`): permitido en cualquier
  estado salvo `cerrada` (para eso está el retorno) o `cancelada`. Libera el
  andén y cancela el despacho directo si había camión. El evento `cancelada`
  separa `folios_liberados` y `pendientes_reingreso`.
- **Quitar** (`DELETE /api/cargas/{carga}/folios/{folio}`): ahora también con la
  separación iniciada. La carga debe conservar al menos un pallet; si no queda
  ninguno, se cancela. El estado se recalcula (por ejemplo, de
  `despacho_parcial` a `en_preparacion` si ya no queda nada en el andén).
- **Cambiar** (`POST /api/cargas/{carga}/folios/{folio}/cambiar`): entra un
  pallet disponible en cámara. Si difiere en cliente, especie, variedad,
  calibre o embalaje, responde `409 confirmacion_requerida` con las
  advertencias; con `confirmar_diferencias=true` se aplica. No bloquea: la
  decisión es comercial. El pallet que entra queda `pendiente` y la carga
  vuelve a `despacho_parcial` si ya estaba toda en el andén.
- Las incidencias abiertas del pallet que sale se cierran como
  `retirado_de_carga` con el motivo.
- Un pallet pendiente de reingreso no puede asignarse a otra carga (código
  `pendiente_reingreso`) hasta que vuelva a una posición de cámara. Al ubicarlo,
  el reingreso se cierra con ese movimiento; el folio conserva su número.

## 3. Bandeja de reingreso a cámara

Cada pallet pendiente de reingreso (`reingresos_folio_camara`) guarda origen
(carga cancelada, pallet quitado, pallet cambiado o carga retornada), carga,
andén, motivo, quién lo registró y cuándo.

- **Tarea guiada**: al confirmarse la operación se publica un plan
  `almacenamiento_pallet` (referencia `reingreso_camara`) con una tarea de
  ubicación inicial, prioridad alta y horizonte rolling. El destino lo calcula
  el servidor, como el resto del planificador; la tablet no calcula nada. La
  tarea se publica fuera de la transacción de la carga para no encadenar el
  bloqueo de la temporada detrás de los bloqueos de la carga. Si el
  planificador está apagado, el pallet sigue en la bandeja y se ubica a mano;
  oficina puede reintentar con `POST /api/cargas/reingresos/{id}/programar`.
- **Temperatura de pulpa**: opcional. La tarea y la tablet la piden, pero no
  bloquea la ubicación ni cambia el folio
  (`POST /api/cargas/reingresos/{id}/temperatura`, entre −5 y 40 °C). Lo
  registra el camarero del andén o quien gestiona cargas.
- **Cierre**: cuando el pallet vuelve a una posición (por la tarea o a mano) el
  reingreso queda `ubicado` con el movimiento y la cámara. Si se ubicó a mano,
  la tarea guiada que nadie tomó se cancela.
- **Dónde se ve**: `GET /api/cargas/reingresos` (tablet y oficina), la pestaña
  Despacho de Operación ahora (pendientes primero, con espera, temperatura y
  estado de la tarea) y Existencias de PT («Pendiente de reingreso a cámara»).
  Mientras está pendiente no cuenta como disponible para despacho.

## 4. Retorno de una carga cerrada

Al registrar un retorno se emite un aviso para el equipo de productos con la
carga, los folios y su cantidad. Leer o confirmar ese aviso no ubica los pallets:
la alerta de Operación ahora permanece mientras haya reingresos pendientes.

Las tareas se identifican como **Reingresar pallets a cámara**, seleccionable
como función principal o apoyo en Labores por turno. La bandeja distingue el
retorno comercial guardado de su labor programada y muestra el motivo si falta
programación. Oficina, con permiso de gestionar cargas, puede usar **Programar
labor**; se conserva cualquier trabajo activo del folio y su responsable.

El scheduler ejecuta `cargas:recuperar-reingresos` cada minuto, con un margen de
cinco minutos entre intentos fallidos. También puede ejecutarse manualmente tras
aplicar la migración. Recupera pendientes de la temporada activa sin repetir la
devolución ni crear otra tarea para un folio comprometido. Si la labor anterior
fue cancelada, conserva su historial y utiliza un nuevo ciclo de referencia.

Cuando el camión o el contenedor vuelve a planta después del cierre
(`POST /api/cargas/{carga}/retorno`, permiso `gestionar-cargas`):

- **Total** (sin lista de folios): todos los pallets despachados vuelven; la
  carga queda `retornada`.
- **Parcial** (`folios`: los que volvieron): la carga queda `retorno_parcial`
  y lo demás sigue `despachado`. Admite retornos sucesivos hasta completar.
- Cada pallet retornado: la asignación pasa a `retornado`, el folio vuelve a
  `activo` y `disponible` **con el mismo número**, sin ubicación, y queda
  pendiente de reingreso (origen `retorno_carga`) con su tarea guiada.
- Exige motivo y hora de llegada (no futura ni anterior a la salida). Solo
  dentro de la temporada activa: el registro de otra temporada responde
  `temporada_no_activa`.
- Recalcula el saldo SAG de los pallets (`retorno_despacho`) para que vuelvan
  a contar en las existencias inspeccionadas.
- El reporte «Despachos PT» conserva lo que sí salió en un retorno parcial y
  omite lo que volvió.
- Los estados `retornada` y `retorno_parcial` son terminales para el cierre de
  temporada y las reglas de integridad, igual que `cerrada`.

## Operación ahora · pestaña Despacho

Una fila por carga publicada con dos barras:

- **Separación** = pallets acopiados juntos en la banda designada o ya en el
  andén, sobre el total (por ejemplo 10/20). Es la misma concentración que usan
  el planificador y la tablet, así las tres vistas muestran el mismo número.
- **Cargados** = pallets leídos al subir al camión, sobre el total, más cuántos
  esperan en el andén.

También muestra orden externa, embarque, cliente, andén, camión presente,
incidencias abiertas y si la carga está lista para cerrar.

## Corrección incluida

El reporte «Despachos de producto terminado» filtraba pallets `en_anden`, pero
el cierre los deja `despachado`: las cargas cerradas no aparecían. Ahora lee
`despachado` (y `en_anden` para cierres antiguos).
