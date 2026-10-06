# Recepción de fruta embalada · PR 1

La captura registra un camión y sus pallets en borrador. No pasa por Romana y no crea ni modifica folios, movimientos, reservas, tareas o habilitaciones térmicas. La aceptación, anulación operacional, RRFE-01 y etiquetas corresponden a los PR 2 y 3.

## Acceso y configuración

- Oficina: **Frío → Recepción de fruta embalada** (`/oficina/recepcion-fruta-embalada`). Listado paginado por fecha, cliente, planta y estado; detalle editable solamente en borrador de la temporada activa.
- Administración: **Plantas y umbrales de prefrío** (`/oficina/administracion/fruta-embalada`). Plantas con código, nombre y activa; umbrales en °C por nombre normalizado de especie. No se precargan valores térmicos: el administrador debe definirlos.
- PDA y tablet: módulo **Recepción de fruta embalada** (`recepcion_fruta_embalada`), disponible en las variantes tablet y PDA. Mismo catálogo PT y asociaciones CSG–variedad del servidor.
- Permiso Oficina: `frigorifico.recepcion-fruta-embalada`. Permiso de token móvil: `tablet:recepcion_fruta_embalada`.
- Los perfiles predeterminados de Administrador, Supervisor de Frío y Validador incorporan el módulo; Consulta dispone de lectura en Oficina. Los perfiles personalizados requieren habilitarlo explícitamente en Administración. La administración de plantas y umbrales usa `administrar-accesos`.
- Después de migrar, cerrar sesión y volver a ingresar en Oficina y dispositivos: los permisos y habilidades se emiten al iniciar sesión.

Los catálogos administrativos son globales, como los demás maestros. Las escrituras de recepción y detalle verifican la temporada activa dentro de la transacción y el control común de rutas.

## Captura

1. Elegir cliente y planta de origen, completar guía, servicio, turno, validador, fecha/hora de recepción, chofer y patente delantera. Salida, RUT, patente del carro y observación pueden completarse después; si se informa RUT, se valida su dígito verificador.
2. Indicar si llega con prefrío y la condición SAG para todos los pallets. Una guía coincidente para el mismo cliente y planta muestra aviso y necesita confirmación antes de guardar; no impide una recepción legítima duplicada.
3. Escanear o escribir el folio de origen. Un folio vigente coincidente muestra **«Se asignará folio interno al aceptar»**. También se informa una repetición dentro de la propia recepción.
4. Seleccionar CSG, especie, variedad, embalaje, calibre y cajas, en ese orden. Completar pallet/saldo, fecha de proceso de origen, T° de pulpa y CSP. La condición SAG se hereda o se personaliza; **Sin condición SAG** es un ajuste individual explícito.
5. Agregar cada pallet a la captura y guardar el borrador. Se admite guardar primero el encabezado sin pallets. Máximo 500 bultos por recepción; listado de 25 por página.

En PDA las fechas se ingresan como `AAAA-MM-DDTHH:mm` (hora del dispositivo), y la fecha de proceso como `AAAA-MM-DD`. Las fechas de recepción/salida se transmiten en UTC con zona explícita. Los selectores usan listas virtualizadas e índices comerciales de #383; editar cantidades o temperaturas no recalcula las asociaciones.

Ante una respuesta perdida, se bloquea la edición y se reenvían exactamente el mismo UUID y payload. La PDA conserva ese envío localmente por servidor, usuario y dispositivo, incluso al reiniciar la pantalla. Oficina conserva el intento durante la sesión de la pestaña y advierte al salir con cambios pendientes. Una respuesta HTTP de validación/conflicto permite corregir; un corte de red o error 5xx mantiene el reintento. No se ofrece guardar inventario desde estas pantallas.

## Contrato para PR 2 y PR 3

| Dato | Persistencia / regla |
| --- | --- |
| Encabezado | `recepciones_fruta_embalada`; UUID, `operacion_id` de creación, `temporada_id`, `cliente_id`, `planta_origen_id`, `numero_guia`, `servicio`, `turno`, `validador_id`, `recepcion_at`, `salida_at`, datos de transporte, `llega_con_prefrio`, `condicion_sag_id`, `observacion`, `estado`, `version`, autoría y timestamps. |
| Detalle | `recepciones_fruta_embalada_pallets`; UUID, recepción, `orden`, `folio_origen`, `tipo_bulto`, artículo/origen PT y snapshots `embalaje`, `especie`, `variedad`, `csg`, `csp`, `calibre`, `cantidad_cajas`, `fecha_proceso_origen`, `temperatura_pulpa_c`, SAG. |
| SAG | `condicion_sag_personalizada = false`: guardado aplica SAG del encabezado. `true`: conserva SAG individual, incluso `null`. Cada detalle conserva su condición efectiva. |
| Umbral | `umbrales_prefrio_especies.especie` en mayúsculas, único global; `temperatura_maxima_c` decimal de dos posiciones. PR 1 solamente configura; no evalúa destinos. |
| Auditoría | `eventos_recepcion_fruta_embalada`: UUID de operación único, hash del payload, actor/dispositivo, snapshots antes/después. Catálogos: `eventos_catalogo_fruta_embalada`. |
| Concurrencia | `version_conocida` obligatoria en PUT; conflicto 409 si otra sesión modificó el borrador. El guardado bloquea temporada y recepción. Cada operación es atómica e idempotente. |
| Estados | enum `EstadoRecepcionFrutaEmbalada`: `borrador`, `aceptada`, `anulada`; PR 1 escribe únicamente `borrador`. PR 2 debe implementar sus transiciones y permisos. |

API bajo `/api/recepciones-fruta-embalada`:

- GET `/`, `/{id}`, `/opciones`, `/catalogo-pt` (ETag), `/revisar-guia`, `/revisar-folio`.
- POST `/`: encabezado más `operacion_id` UUID y `pallets` (puede estar vacío).
- PUT `/{id}`: misma captura más `version_conocida`. Cada fila existente conserva su UUID; filas omitidas se eliminan solo del borrador. No hay endpoint de borrado del encabezado.
- Los detalles recibidos solo permiten datos de captura: no aceptan `folio_id`, `estado` ni snapshots comerciales manipulados. Estos últimos se derivan del catálogo PT validado en el servidor.
- `/revisar-guia`: `cliente_id`, `planta_origen_id`, `numero_guia`, `excluir_id` opcional; devuelve `duplicada`, `mensaje`, hasta 5 coincidencias. Al guardar la duplicada se requiere `confirmar_guia_duplicada: true`.
- `/revisar-folio`: `folio_origen`; devuelve `repetido` y `mensaje`. Es informativo; PR 2 debe volver a verificar el número al aceptar.

Administración usa `/api/administracion/fruta-embalada`: GET raíz, POST `/plantas` o `/umbrales`, PUT `/plantas/{id}` o `/umbrales/{id}` con `version_conocida`.

## Prueba en el servidor

Aplicar el procedimiento habitual con respaldo de `estiba_wms`, actualizar dependencias, ejecutar `php artisan migrate --force`, `php artisan optimize:clear` y `npm run build`. Publicar el móvil en los canales instalados (production, pda y pda-pruebas) según el procedimiento del proyecto. El cambio no requiere una dependencia nativa nueva. No se deben sembrar datos ficticios en la base operativa.

1. En Administración crear una planta de origen activa y el umbral de **UVA** autorizado por la planta.
2. Entrar nuevamente en la PDA, abrir el nuevo módulo y capturar un camión de 6 pallets con catálogo comercial real. Usar un folio ya vigente en uno de ellos; comprobar el aviso al escanear.
3. Aplicar una condición SAG del encabezado, ajustar otro pallet y dejar uno sin condición. Guardar y reabrir en Oficina: 6 filas, cajas y temperaturas correctas, misma condición efectiva por pallet.
4. Intentar repetir guía para el mismo cliente y planta: debe avisar y pedir confirmación. Probar CSG de otro cliente/variedad no asociada mediante la API: el servidor debe rechazar sin persistir cambios.
5. Confirmar en Consultas y Operación ahora que no aparecieron folios ni tareas nuevos. La recepción continúa **borrador**.
6. Desde dos sesiones editar el mismo borrador: la segunda debe recibir conflicto si conserva una versión antigua. Simular respuesta perdida y reintentar: una sola recepción y un solo evento para ese UUID.

La aceptación del camión queda pendiente del PR 2; el documento RRFE-01 y etiquetas, del PR 3.
