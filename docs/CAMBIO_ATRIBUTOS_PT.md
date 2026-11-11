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

El número de folio sigue la regla de Validación: solo se cambia antes de cargarlo a prefrío.

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

Todas exigen `cambiar-atributos-pt`.

Pruebas: `tests/Feature/Api/CambioAtributosPtApiTest.php`,
`tests/Feature/Integraciones/ConciliacionValidacionOrigenTest.php` (aceptación frente a planta),
`tests/Browser/OfficePtAttributesTest.mjs`.
