# RRFE-01 y etiquetas externas

Depende de #395 (aceptación) y #394 (captura). Orden de integración: PR 1 → PR 2 → PR 3.

## RRFE-01

Formato de registro RRFE-01, versión 1, fecha 02-03-2026, localidad Rengo. PDF horizontal con logo, encabezado del camión, tabla oficial y veinte filas por hoja, total de cajas de la hoja y de la recepción, observaciones y firmas en blanco de Supervisor de frío y Jefe de frigorífico. No imprime guía ni condición SAG. Se usan los folios asignados al aceptar y la captura original de la recepción, incluso si su inventario se repaletiza después.

La primera emisión guarda `formato_rrfe_snapshot` bajo bloqueo de temporada y recepción, incrementa versión y audita antes/después. Los cambios posteriores del catálogo de formatos no alteran esa versión. Un fallo de generación no registra una emisión. La versión en blanco usa el formato vigente y veinte filas vacías. Observaciones extensas se conservan en anexos con encabezado y firmas.

- `GET /api/recepciones-fruta-embalada/rrfe-01/blanco`.
- `POST /api/recepciones-fruta-embalada/{id}/rrfe-01`, recepción aceptada de temporada activa.

## Etiquetas

Las recepciones usan la etiqueta de planta de #393: 107 × 74 mm, cuatro copias por defecto (1 a 10), origen EXTERNO y fecha de proceso de origen persistida. Folio y ventana conservan sus formatos. La selección global de etiquetas incorpora filtro Externo.

Oficina y PDA destacan las etiquetas obligatorias de folios internos y permiten generar el PDF por folio. Cuando falta peso de algún envase, avisan antes de generar; la etiqueta imprime —. La API devuelve composición, pesos faltantes y versión revisada. El endpoint de recepción permite solamente folios de esa recepción, el formato planta y los permisos/habilidad del módulo.

- `POST /api/recepciones-fruta-embalada/{id}/etiquetas`: `{operacion_id, temporada_id, folios: [{id, version}], copias?, motivo_reimpresion?}`.

Se reutiliza `ImpresionEtiquetaPt`: usuario, copias, formato, snapshot, enlaces y operación idempotente. El estado pendiente se deriva de ese historial, no de una marca manual. La generación exitosa del PDF cuenta como impresión igual que en el flujo PT existente; el dispositivo abre la opción de compartir el PDF y Oficina lo descarga para imprimir. La segunda generación requiere motivo.

## Verificación física pendiente

Imprimir las etiquetas y RRFE-01 de la recepción aceptada del camión de seis pallets; comparar con la etiqueta física y la planilla en papel. No se ha operado el servidor de planta ni se recibió una copia digital de la planilla física en esta conversación; el diseño implementa los campos y orden especificados.
