# Portal de clientes

Usuarios externos de una exportadora consultan lo suyo sin pasar por la Oficina interna.

**Entrada:** `/portal` (redirige a `/oficina/clientes`). La entrada única `/FoliOS` también
lleva a una cuenta de cliente a su portal. Si una cuenta de cliente abre una oficina interna,
el arranque la devuelve a `/oficina/clientes`. Si una cuenta interna abre el portal, se le
indica volver a su Oficina.

## Qué ve el cliente

| Sección | Contenido | Fuente |
|---|---|---|
| Resumen | Kilos y recepciones de la temporada; kilos de los últimos 7 días; folios y cajas en planta; embarques próximos; solicitudes en espera; ítems de materiales con existencia | Varias |
| Recepciones | Recepciones de Romana cerradas, con kilos netos, guías y lotes (variedad, CSG, kilos y guía de cada lote). Filtro por fecha y totales por especie | `recepciones_romana`, `guias_recepcion_romana`, `lotes_materia_prima` |
| Embarques | Embarques de la temporada: estado, modalidad, nave, puerto, contenedor, instructivos (destino, pallets, cajas) y avance de la carga | `embarques`, `instructivos_embarque`, `cargas` |
| Horario de carga | Bloques del calendario como libre, ocupado o propio, sin mostrar de quién son los ocupados. Desde ahí solicita un horario y puede anularlo mientras espera respuesta | Calendario de embarques |
| Folios | Folios PT en planta, despachados o todos, con estado, producto, cajas, cajas por CSG y fechas. **Sin cámara ni posición** | `folios` |
| Materiales | Existencia, reserva y disponible por ítem de sus materiales en bodega | Inventario de materiales |

Solo se muestran datos de la temporada activa. No se exponen personas, conductores, patentes,
ubicaciones físicas ni datos de otros clientes.

## Usuarios

**Accesos → Usuarios de clientes** (administrar accesos) crea y edita las cuentas. Cada cuenta:

- Tiene rol `cliente`, sin perfil de acceso ni módulos internos.
- Se vincula a una o varias exportadoras (`usuarios_clientes`). Con más de una, el portal permite
  elegir la exportadora. Nunca mezcla datos de dos exportadoras en una vista.
- Nace con contraseña temporal. El portal exige cambiarla al primer ingreso.
- Si se cambia su correo, contraseña, estado o exportadoras, se cierran sus sesiones abiertas.

Estas cuentas no aparecen en **Usuarios** ni pueden pasar a un rol interno desde ahí. Tampoco
se puede elegir `cliente` en un usuario o perfil interno.

## Aislamiento

Hay tres capas, independientes entre sí:

1. **Middleware `RestringirUsuarioCliente`** (al inicio del grupo `api`). Con un token de
   cliente, cualquier ruta que no sea `/api/portal/*`, `/api/acceso-oficina` o el cambio de
   contraseña responde `403 solo_portal_clientes`. Esto vale aunque la ruta interna no tenga
   un permiso propio.
2. **`Gate::before`**. Un usuario de cliente no obtiene ninguna habilidad interna, salvo
   `portal-cliente`.
3. **`AlcancePortalCliente`**. Cada consulta del portal recibe un cliente ya validado contra
   las exportadoras de la cuenta. Un cliente no asignado responde 404.

Los folios PT registran la exportadora como texto (`folios.exportadora`). El portal la
compara, sin distinguir mayúsculas ni espacios extremos, con:

- el nombre y código del cliente;
- sus nombres en los catálogos de Validación;
- sus alias.

Un texto que también identifica a otro cliente se descarta por ambiguo.

## Solicitudes de horario de carga

```
cliente solicita bloque libre ──► solicitada ──► aceptada  (despacho: crea el embarque tentativo)
                                     │       └──► rechazada (despacho, con motivo visible al cliente)
                                     └──────────► anulada   (el cliente, mientras espera)
```

- Solo se piden bloques futuros, alineados al intervalo de la temporada y dentro del próximo mes.
- Una solicitud pendiente reserva el bloque frente a otros clientes. Dos clientes no pueden
  pedir el mismo bloque, y un bloque con embarque tampoco se puede pedir.
- Las solicitudes son idempotentes por `operacion_id` y huella del contenido. El rechazo y la
  aceptación usan `version_esperada`.
- **Despacho → Calendario de embarques** muestra el panel «Solicitudes de horario» y las marca
  en su bloque. **Aceptar** llama a la misma creación de embarques del calendario, con:
  - modalidad;
  - pallets como instructivo;
  - referencia como booking y referencia de correo;
  - observación con el código de la solicitud.

  Si el bloque quedó ocupado, quien puede autorizar sobrecupos lo hace con su motivo. El
  cliente necesita su sigla documental de dos letras, igual que en cualquier embarque.

## API

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/portal/contexto` | `portal-cliente` |
| GET | `/api/portal/clientes/{cliente}/resumen` · `/recepciones` · `/embarques` · `/folios` · `/materiales` · `/horarios` · `/solicitudes-carga` | `portal-cliente` + cliente asignado |
| POST | `/api/portal/clientes/{cliente}/solicitudes-carga` · `/solicitudes-carga/{id}/anular` | `portal-cliente` + cliente asignado |
| GET | `/api/solicitudes-carga-clientes` | `consultar-catalogo-cargas` |
| POST | `/api/solicitudes-carga-clientes/{id}/aceptar` · `/rechazar` | `gestionar-cargas` |
| GET / POST / PUT | `/api/administracion/usuarios-clientes` | `consultar-accesos` / `administrar-accesos` |

Pruebas: `tests/Feature/Api/PortalClientesTest.php`, `tests/Browser/ClientPortalTest.mjs`,
`tests/Browser/OfficeShipmentRequestsTest.mjs`.
