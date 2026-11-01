# Órdenes de proceso de materia prima

Fruta a proceso pasa a trabajar contra una orden de proceso que crea el digitador MP a partir de la PRE-SIF, en lugar de recibir línea, turno y número de orden como texto libre en cada entrega.

Especificación acordada el 1 de noviembre de 2026. Se entrega en cuatro PR que se integran en orden:

1. Catálogos de líneas y turnos, y permisos.
2. Órdenes, versiones y lotes de versión, con la oficina del digitador.
3. Fruta a proceso amarrada a la orden (API y APK).
4. Exportación PRE-SIF y vista de programado contra real.

## Reglas de negocio

1. El digitador MP traspasa la PRE-SIF a FoliOS y crea la orden. Las filas que no aplican, vacías u ocultas no se traspasan.
2. El N° de proceso lo ingresa el digitador y va por lote, igual que en la PRE-SIF.
3. Puede haber varias órdenes abiertas a la vez: la planta tiene 5 líneas.
4. En un mismo turno pueden procesarse 3 o 4 clientes; el cliente va por lote, no por orden.
5. Una orden tiene versiones (v1, v2, v3…). Mientras está en proceso no se cierra: solo cambia de versión.
6. La orden se cierra solo al terminar: por entrega completa (automático) o por cancelación. Ambos casos quedan como terminada, con su tipo de cierre.
7. Un lote puede quedar de un turno a otro. Se planifica con horarios estimativos.
8. Entregar un lote que no está en la orden requiere autorización del supervisor y entra a la orden como nueva versión.

## Líneas de proceso

`lineas_proceso` reemplaza el texto libre de línea. La migración crea L1 a L5 con 6.000 kg/h, el rendimiento que usa hoy la PRE-SIF.

| Campo | Uso |
| --- | --- |
| `codigo` | L1…L5, único. |
| `nombre` | Nombre visible. |
| `rendimiento_kg_hora` | Base para calcular horarios estimados de la orden. Entre 100 y 100.000. |
| `activa` | Una línea inactiva no recibe órdenes nuevas. |
| `version` | Bloqueo optimista: un cambio con versión antigua responde 409. |

## Turnos

`App\Enums\TurnoProceso` mantiene A/B como código FoliOS y traduce los códigos de planilla:

| FoliOS | PRE-SIF | Instructivo |
| --- | --- | --- |
| A | T1 | AM |
| B | T2 | PM |

`TurnoProceso::desdeCodigo()` acepta cualquiera de los tres.

## Permisos

Módulo de oficina `materia-prima.ordenes-proceso`.

| Capacidad | Gate | Roles |
| --- | --- | --- |
| Consultar órdenes y catálogos | `consultar-ordenes-proceso` | Administrador, supervisor de frío, digitador MP, consulta |
| Crear y versionar órdenes | `gestionar-ordenes-proceso` | Administrador, supervisor de frío, digitador MP |
| Configurar líneas | `configurar-lineas-proceso` | Administrador, supervisor de frío |

La migración agrega el módulo a los perfiles predeterminados de esos roles. Autorizar una entrega fuera de orden usa la capacidad existente de corrección de Fruta a proceso (administrador y supervisor de frío).

## API

```http
GET /api/materia-prima/ordenes-proceso/catalogos
PUT /api/materia-prima/lineas-proceso/{linea}
```

`catalogos` devuelve las líneas ordenadas y los turnos con sus códigos de planilla. `PUT` acepta `nombre`, `rendimiento_kg_hora`, `activa` y exige `version`.
