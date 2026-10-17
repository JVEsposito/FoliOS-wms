# Anulaciones de pallets validados

## Propósito

La anulación corrige un error operacional detectado después de aprobar una validación PT, pero antes de que el pallet participe en cualquier etapa posterior. No elimina la validación ni el folio: conserva ambos para auditoría y deja el folio permanentemente fuera de operación.

La anulación es distinta de un rechazo. Un rechazo es una decisión tomada durante la validación; una anulación declara inválida una aprobación que ya había creado un folio.

## Quién puede anular

La operación utiliza el permiso existente `rechazar-pallets`, reservado a supervisor de frío y administración dentro del módulo de Validación PT.

Los usuarios con permiso de consulta pueden revisar el historial, pero no anular.

## Condiciones obligatorias

Solo puede anularse una validación que:

- esté aceptada y aprobada;
- conserve su folio creado directamente por Validación;
- tenga el folio activo;
- continúe en `pendiente_prefrio`;
- mantenga condición térmica `pendiente_prefrio`;
- continúe sin habilitación de almacenamiento;
- no tenga ubicación ni movimientos;
- no tenga asignaciones ni reservas de carga;
- no tenga ninguna participación en procesos de prefrío;
- no haya participado en un repaletizaje como origen, folio conservado o resultado.

Si cualquiera de estas condiciones deja de cumplirse, la anulación se rechaza.

## Resultado de la anulación

Al confirmar:

- la validación pasa a estado `anulada`;
- el folio queda `activo = false`;
- el estado operacional del folio pasa a `anulado`;
- se elimina cualquier habilitación de almacenamiento;
- se registra el identificador de la anulación en los datos externos del folio;
- el folio queda protegido contra futuras mutaciones.

Un folio anulado por Validación no puede volver a utilizarse para:

- ubicación o movimientos de cámara;
- cargas o reservas;
- prefrío;
- repaletizajes;
- reactivación o cambios posteriores.

El número de folio permanece ocupado como identidad histórica. No se reutiliza para crear otro pallet.

## Auditoría

La tabla `anulaciones_validacion_pallet` conserva:

- UUID idempotente de la operación;
- validación y folio afectados;
- número de folio;
- categoría del error;
- motivo detallado;
- usuario que anuló;
- fecha y hora;
- snapshot completo de la validación y del folio antes de la anulación.

Categorías disponibles:

- folio incorrecto;
- cantidad de cajas incorrecta;
- artículo incorrecto;
- cliente u origen incorrecto;
- pallet duplicado;
- error de etiqueta;
- otro.

## Oficina

Ruta:

`/oficina/validacion/anulaciones`

La oficina presenta:

- pallets que todavía cumplen todas las reglas para ser anulados;
- total de anulaciones;
- anulaciones del día operacional;
- motivo más frecuente;
- historial auditable con validador original, supervisor que anuló, fecha y motivo.

La lista de anulables trae hasta 100 pallets de la temporada activa que siguen pendientes de
prefrío (el filtro va en la consulta) y revisa su actividad posterior para todos juntos, con
una consulta por tipo (ubicación, carga, movimientos, prefrío, repaletizaje). El catálogo
para **Corregir datos** no se descarga al cargar la pantalla: se pide al abrir la corrección,
solo con los artículos del cliente del bulto (y de otro cliente si se cambia).

## API

Consulta:

`GET /api/validacion/anulaciones`

Anulación:

`POST /api/validacion/pallets/{validacionPallet}/anular`

Payload:

```json
{
  "operacion_id": "UUID",
  "motivo_categoria": "folio_incorrecto",
  "motivo": "Detalle del error detectado"
}
```

La operación es idempotente: repetir el mismo UUID con el mismo usuario, validación y payload devuelve el registro existente; reutilizarlo con datos diferentes genera conflicto.
