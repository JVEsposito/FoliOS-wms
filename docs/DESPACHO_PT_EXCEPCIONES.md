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
