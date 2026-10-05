# Etiquetas PT en Oficina

La PDA conserva su flujo de Validación PT. Después de una aprobación, en Oficina → Frigorífico → Etiquetas PT se busca el folio, se revisan sus datos y se seleccionan los pallets o saldos para generar el PDF. También hay un acceso desde Validación. Se usa el permiso existente `consultar-validaciones-pallet`, módulo `frigorifico.validacion`, y una sesión de Oficina; un token de PDA no puede generar etiquetas.

## Formatos e impresión

- **Folio:** 100 × 50 mm, folio grande, código de barras y resumen del artículo, cantidad, cliente, marca, CSG y embalaje.
- **Ventana:** 100 × 200 mm, los datos anteriores más temporada, estado operativo, validador, jornada y composición completa por CSG, predio, fecha y cantidad. Incluye lote MP y proceso de packing cuando existen en la composición.
- Código **Code 128**, con el valor exacto del folio, incluidos ceros iniciales. Se admite ASCII imprimible; un folio no representable o demasiado largo se rechaza con un mensaje, nunca se altera silenciosamente.
- Una página por etiqueta; hasta 50 folios y 1–10 copias por folio por solicitud. La pantalla selecciona hasta 25 folios de la página actual; cambiar filtros o página limpia la selección.
- Abrir o descargar el PDF e imprimir al **100 % / tamaño real**, sin ajustar a página, en una impresora con papel del tamaño elegido. Esta primera versión imprime por PDF; no envía comandos ZPL ni archivos NLabel directamente.

Los tamaños son iniciales fijos. Comprobar papel, márgenes y lectura con la impresora real antes de pegar etiquetas. Si un detalle no cabe, el sistema solicita ventana o revisar la composición; no recorta datos sin avisar. El folio compacto es un resumen: para mezclas se debe revisar e imprimir la ventana.

## Datos y trazabilidad

Solo se muestran aprobaciones aceptadas con folio activo en la temporada activa. Se excluyen conflictos, anulaciones, observados, rechazados y folios agotados, despachados o retirados definitivamente. Un folio bloqueado puede etiquetarse, pero su estado sigue bloqueado; imprimir no habilita movimientos ni cambia inventario.

Los datos actuales del folio prevalecen sobre la validación original para reflejar correcciones y la cantidad remanente después de un repaletizaje. Esta pantalla no crea folios ni etiqueta folios nuevos nacidos de un repaletizaje sin una aprobación de Validación PT vinculada.

La revisión devuelve una versión de los datos. Al generar, se bloquean y verifican de nuevo la temporada, validaciones y folios. Si cambiaron, hay que actualizar el listado y revisarlos. Una generación guarda actor, fecha, formato, copias y snapshot. Repetir el mismo formato para un folio requiere motivo de reimpresión. Una descarga fallida puede reintentarse con el mismo identificador de operación sin crear otra auditoría.

El historial registra **generación de PDF**, no confirmación física de impresión. Volver a imprimir el PDF descargado ocurre fuera del sistema; para registrar otra emisión, generar de nuevo con motivo. El PDF entregado conserva la información de ese momento; si cambia el pallet, descartar esa etiqueta y generar otra.

## Despliegue en pruebas (puerto 8001)

1. Respaldar `folios_pruebas` y comprobar que `.env` corresponde al servidor de pruebas.
2. Después de fusionar el PR: `git pull --ff-only origin main`.
3. `php artisan migrate --force`: crea únicamente las tablas de auditoría y su vínculo con folios; no modifica pallets históricos.
4. `npm ci` y `npm run build`.
5. `php artisan optimize:clear` y `php artisan queue:restart`. Si el worker es manual, volver a iniciarlo cuando termine; comprobar `php artisan sistema:estado-procesos`.
6. Abrir Oficina y recargar con Ctrl+F5. **No requiere OTA ni APK nueva.**

## Prueba de aceptación

1. Validar un pallet y un saldo con folios únicos en PDA; confirmar su aparición en Oficina.
2. Buscar por folio exacto, fecha, línea y turno. Revisar cajas, variedad, envase, categoría, cliente, marca y composición.
3. Generar folio y ventana, dos copias; comprobar dimensiones, número de páginas, todos los CSG y lectura exacta del código con PDA.
4. Generar otra ventana del mismo folio: debe exigir motivo y mostrarlo en el historial.
5. Revisar un folio, corregirlo desde otra ventana, intentar generar con la selección anterior: debe pedir actualizar. Tras actualizar, el PDF usa los datos corregidos.
6. Comprobar que no aparecen conflictos ni folios anulados, agotados o despachados. Confirmar que etiquetas no cambian su estado ni su ubicación.

Endpoints: `GET /api/validacion/etiquetas`, `GET /api/validacion/etiquetas/historial`, `POST /api/validacion/etiquetas`. La generación requiere `operacion_id`, `temporada_id`, `tipo`, `copias`, `validaciones: [{id, version}]` y, para reimpresión, `motivo_reimpresion`.
