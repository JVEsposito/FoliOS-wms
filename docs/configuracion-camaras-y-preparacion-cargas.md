# Configuración de cámaras y preparación de cargas

## Vocabulario físico

- **Cámara:** recinto físico en el que se ubican bultos. Su código operacional es estable, por ejemplo `CAM-01`.
- **Banda:** eje numérico vertical dentro de la cámara.
- **Posición:** lugar dentro de una banda. `P01` corresponde al fondo y la numeración avanza hacia la entrada.
- **Nivel:** altura física de una posición, numerada desde `1`.
- **Etiqueta:** código como `B01-P01-N1`.
- **Ubicación completa:** cámara más etiqueta, por ejemplo `CAM-01/B01-P01-N1`.

Los UUID son las claves internas. Los códigos y etiquetas operacionales se mantienen estables cuando existe trazabilidad asociada.

## Contenido de cámaras

Cada cámara declara el tipo de contenido que acepta.

### Producto

- pallets y saldos;
- una posición admite un único folio;
- el folio debe encontrarse activo y cumplir la regla térmica o de habilitación aplicable;
- los folios nacidos en Validación se consultan y autocompletan antes de ubicarse.

### Materiales

- folios con ficha de inventario de Materiales;
- una posición puede contener varias líneas o folios;
- todos los ocupantes de una posición compartida deben pertenecer al mismo cliente;
- la tablet permite seleccionar la línea exacta para mover o retirar.

No se mezclan producto y materiales dentro de una misma cámara.

## Representación del plano

- Cada banda se representa verticalmente.
- El fondo se muestra arriba y la entrada abajo.
- Las posiciones se ordenan desde `P01` hacia la entrada.
- Los niveles se consultan sin alterar la orientación.
- Las posiciones pueden estar activas, bloqueadas o fuera de servicio.
- En Materiales el plano puede mostrar varios ocupantes dentro de una misma posición.

### Bandas operacionales de producto terminado

Las cámaras de producto terminado representan cada banda física como una unidad operacional. Esta capa no reemplaza sus posiciones ni modifica las ubicaciones existentes.

- La capacidad física cuenta las coordenadas vigentes de la banda.
- La capacidad efectiva excluye posiciones bloqueadas o fuera de servicio.
- La ocupación y disponibilidad se calculan desde las ubicaciones actuales.
- El estado calculado puede ser `libre`, `parcial`, `completa`, `bloqueada` o `en_vaciado`.
- Una banda operativa puede admitir tránsito PT, inspección y/o retenidos.
- Saldos y repaletizaje no son usos configurables de estas bandas; se mantienen fuera del planificador inicial de pallets completos.

`bloqueada` impide que el planificador la considere disponible. `en_vaciado` identifica una banda que debe liberar sus ocupantes y no recibir nuevos ingresos. En este incremento ambos modos quedan expuestos para los siguientes generadores de tareas; no producen movimientos automáticos por sí solos.

La migración crea una banda operacional por cada banda vigente de cámaras PT, habilitada inicialmente para tránsito, inspección y retenidos. No mueve folios ni asigna clientes, marcas, formatos o cargas.

### Afinidad dinámica

La afinidad visible de una banda se calcula desde sus pallets completos actuales. Muestra el cliente dominante y, dentro de este, la marca/etiqueta y el formato/envase dominantes. Un cliente activo sin existencia no ocupa una banda; al retirar el último pallet completo, la afinidad queda liberada automáticamente.

Para un pallet completo habilitado, la consulta previa recomienda la mejor posición disponible entre las cámaras PT visibles para el camarero. Prioriza coincidencia `cliente → marca/etiqueta → formato/envase`, después una banda libre y deja una mezcla de clientes como última alternativa explicada. No considera bandas bloqueadas, en vaciado, sin uso de tránsito, completas o intervenidas por saldos/materiales.

La recomendación es una fotografía consultiva y entrega `version_plano`. El movimiento mantiene las validaciones de sesión, capacidad y concurrencia; este incremento todavía no reserva la posición.

## Advertencias físicas

Las reglas físicas orientan al operador, pero determinadas excepciones pueden confirmarse de forma auditada:

1. ocupar una posición dejando otra más profunda disponible;
2. ocupar un nivel superior sin soporte visible;
3. retirar o mover un nivel inferior con otro bulto encima.

La confirmación queda asociada al movimiento, usuario y dispositivo. Una advertencia no confirmada no produce cambios.

## Configuración desde oficina

La oficina `/oficina/camaras` no exige que el PC esté registrado como tablet.

- `supervisor_frio` crea cámaras de producto dentro de su ámbito.
- `supervisor_materiales` crea cámaras de materiales dentro de su ámbito.
- `administrador` puede crear ambas.

La configuración permite:

- crear cámaras;
- definir bandas, posiciones y niveles;
- revisar la cuadrícula antes de guardar;
- marcar posiciones bloqueadas o fuera de servicio;
- crear y mantener andenes según permisos.

Solo el administrador puede editar estructuralmente una cámara existente, ampliar o reducir el plano, cambiar propiedades reservadas, desactivarla o reactivarla.

El administrador también puede configurar los usos y modo de una banda PT mediante `PUT /api/configuracion/camaras/{camara}/bandas/{banda}`. La actualización usa versión optimista, registra al responsable e incrementa `version_plano`. Se rechaza mientras exista una sesión de estiba abierta.

Una reducción no elimina posiciones físicamente: archiva las coordenadas retiradas. Se rechaza si contienen ocupantes. Una cámara tampoco puede desactivarse con inventario o una sesión abierta.

## Sesiones de estiba

- La consulta del plano es concurrente.
- Una sola sesión modifica cada cámara.
- La sesión identifica usuario y dispositivo.
- Un traslado entre cámaras exige autorización válida sobre los extremos afectados.
- Un cierre forzoso requiere supervisión del área o administración y motivo obligatorio.
- Cada movimiento aceptado incrementa la versión del plano.

## Consulta previa del folio

Antes de una ubicación inicial, la interfaz puede consultar el número de folio.

Para un folio existente recupera:

- tipo de bulto;
- estado operacional;
- condición térmica;
- habilitación de almacenamiento;
- condición SAG;
- variedad;
- calibre;
- marca;
- exportadora;
- ubicación actual;
- ficha de Materiales cuando corresponde.
- recomendación explicada de cámara, banda y posición cuando es un pallet completo habilitado.

Los datos nacidos en Validación no se reescriben desde Cámara.

Un folio aprobado en Prefrío puede ejecutar su primera ubicación cuando mantiene:

```text
estado_operacional = pendiente_prefrio
condicion_termica = prefrio_aprobado
habilitacion_almacenamiento = habilitado
```

Después de ubicarse pasa a `disponible`.

La tablet nunca crea folios. Si el número no existe, la ubicación se rechaza con «El folio … no existe. Valida el pallet en Validación PT antes de ubicarlo en cámara; si lo digitaste, revisa el número.». Antes, la tablet permitía registrar un «folio nuevo» que quedaba disponible sin Validación PT ni prefrío.

### Folios creados a mano antes del bloqueo

Los folios que la tablet creó así (`origen_sistema = manual`) muestran en el expediente de Consultas «Creado a mano en cámara, sin validación». Un administrador puede eliminarlos desde ese expediente, con motivo, mientras conserven esa condición: sin validación ni paso por carga, prefrío, repaletizaje, inspección SAG, retención o una labor en curso. La eliminación:

- anula e inactiva el folio, sin borrarlo físicamente;
- libera su posición en la cámara y cancela sus labores pendientes;
- archiva su número con el sufijo `-ELIM-n`, para que el pallet real pueda validarse con el número original;
- anula sus estadías en Finanzas;
- queda registrada con usuario, fecha, motivo y snapshot (`eliminaciones_folio_manual`).

## Preparación de cargas

Una carga `CAR-*` agrupa entre 1 y 26 folios de producto para una orden de embarque.

El flujo incluye:

```text
borrador
→ publicación
→ separación
→ envío a andén
→ cierre de despacho
```

Reglas principales:

- un folio solo mantiene una asignación vigente;
- publicar exige folios activos, elegibles y ubicados;
- la distribución por cámara se calcula desde las ubicaciones actuales;
- la carga conserva una versión para controlar concurrencia;
- cancelar libera asignaciones, pero conserva eventos;
- las cargas pertenecen a una temporada y no aceptan folios de otro ciclo.

## Separación física

El sistema calcula la concentración de los folios pendientes de una carga dentro del plano. Puede distinguir:

- grupo principal;
- folios faltantes;
- obstáculos de otras cargas;
- tareas por cámara;
- plan de extracción.

Los estados visuales de separación orientan la operación, pero cada folio mantiene su ubicación y tarea individual hasta ser enviado.

## Incidencias

Durante la extracción pueden registrarse incidencias por asignación. La resolución no borra el reporte original; agrega la evidencia y decisión correspondiente.

## Envío a andén

Enviar un folio al andén:

- retira el folio de su ubicación mediante un movimiento auditable;
- registra carga, folio, cámara, posición, andén, usuario, dispositivo y fecha;
- actualiza la tarea y el avance de la carga;
- no permite dobles salidas del mismo folio.

La carga queda despachada o cerrada cuando se cumplen las condiciones documentales y físicas del servicio de despacho.

## Andenes

Los andenes son destinos configurables, por ejemplo `AND-01`. Su administración depende de capacidades específicas y no convierte el andén en una cámara de almacenamiento.
