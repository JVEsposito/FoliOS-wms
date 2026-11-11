# Cambio de atributos PT

**Administración → Cambio de atributos PT** (`/oficina/administracion/atributos-pt`). Solo para
administradores, con el módulo `administracion.atributos-pt` (los perfiles de administrador
predeterminados lo reciben al migrar).

Corrige un folio PT (pallet o saldo) de la temporada activa mientras siga en la planta. No se
puede cambiar un folio despachado, anulado, retirado, agotado ni inactivo.

## Qué cambia

| Atributo | Cómo |
|---|---|
| Producto: especie, variedad, calibre, embalaje, categoría | Diálogo de corrección de Validación, con el catálogo de la temporada |
| Cajas y composición por CSG (con lote y proceso) | Mismo diálogo y mismas reglas que la PDA |
| Tipo de bulto, línea, turno, fechas | Mismo diálogo |
| Estado de prefrío | Prefrío aprobado, condición heredada, pendiente de prefrío, requiere reproceso o retenido |
| Número de folio | Mismo diálogo. Se corrige también después de prefrío o ya en cámara, mientras el bulto siga en la planta |
| Anular la validación | Tarjeta «Anular validación», con categoría, motivo y confirmación |

El número de folio se corrige (aquí y en Validación) mientras el folio esté activo y no despachado,
anulado, retirado ni agotado. Todo lo operativo apunta al folio por su id (ubicación, prefrío,
movimientos, cargas), así que el folio conserva su historia. El número anterior queda en la
auditoría de la corrección. Después hay que **reimprimir la etiqueta**.

### Anular una validación

Sirve para un pallet que no existe, quedó duplicado o se validó con datos que no corresponden.

- **Sin actividad todavía** (pendiente de prefrío, sin ubicación, carga ni movimientos): usa la
  anulación normal de Validación. El folio queda anulado con su número, y ese número puede volver a
  validarse.
- **Con actividad** (después de prefrío, ubicado en cámara, con movimientos): el folio queda
  anulado e inactivo **con todo su historial**. Se libera su posición en el plano y se cancelan sus
  labores pendientes. Su número se archiva con el sufijo `-ANUL-n`, así el pallet correcto se
  valida con ese número como un folio nuevo, sin heredar la historia del erróneo. La anulación
  queda en `anulaciones_validacion_pallet` con una copia completa: validación, folio, ubicación,
  procesos de prefrío y labores canceladas.
- No se anula lo que otro proceso todavía necesita. La pantalla dice qué hacer primero si el pallet:
  - está en una carga;
  - está en un túnel;
  - está en un lote SAG abierto;
  - está retenido;
  - participó en un repaletizaje;
  - lo está moviendo un camarero.

### Estado de prefrío

- **Prefrío aprobado / condición heredada.** Habilita el almacenamiento con fuente
  `correccion_administrativa`. El folio queda disponible si está en cámara, o pendiente de
  ubicación si no. Si estaba retenido, se libera la retención y se restituye su carga.
- **Pendiente de prefrío / requiere reproceso.** Vuelve a esperar túnel. Conserva su
  ubicación y su carga, porque un pallet pendiente puede cargarse.
- **Retenido.** Usa la retención operacional: bloquea el folio, suspende su carga y planifica
  su segregación.
- «En proceso» no se puede elegir: solo lo asigna un túnel. Mientras el folio esté en un
  proceso de prefrío activo, su estado no se cambia aquí.

## Trazabilidad

Nada se reescribe. Cada cambio deja:

- una fila en `cambios_atributos_pt` con antes, después, motivo (mínimo 10 caracteres),
  administrador y carga en la que estaba el folio;
- el registro propio del dominio: `correcciones_validacion_pallet` para el producto, y
  `historial_habilitaciones_almacenamiento` y las retenciones operacionales para el prefrío;
- un evento «cambio_atributos» en el expediente del folio (Consultas).

Las operaciones son idempotentes por `operacion_id`.

Si el folio está en una carga, la pantalla lo avisa y el cambio igual se aplica.

## Cuadratura

Un cambio de producto hecho aquí se **acepta como la verdad** frente a planta. Si lo validado
difiere de la copia de planta, el caso queda `cuadrado` con el evento `ajuste_administrador`.
Sigue cuadrado mientras lo validado no vuelva a cambiar (se guarda la huella de lo aceptado en
`aceptado_datos_hash`). Una corrección posterior desde Validación vuelve a compararse con
normalidad.

## Etiqueta

**Reimprimir etiqueta** abre Etiquetas PT con el folio seleccionado.

## API

| Método | Ruta |
|---|---|
| GET | `/api/administracion/atributos-pt/folios?folio=…` · `/folios/{folio}` |
| GET / POST | `/api/administracion/atributos-pt/folios/{folio}/producto` |
| POST | `/api/administracion/atributos-pt/folios/{folio}/condicion-termica` |
| POST | `/api/administracion/atributos-pt/folios/{folio}/anular` |

Todas exigen `cambiar-atributos-pt`.

Pruebas: `tests/Feature/Api/CambioAtributosPtApiTest.php`,
`tests/Feature/Integraciones/ConciliacionValidacionOrigenTest.php` (aceptación frente a planta),
`tests/Browser/OfficePtAttributesTest.mjs`.
