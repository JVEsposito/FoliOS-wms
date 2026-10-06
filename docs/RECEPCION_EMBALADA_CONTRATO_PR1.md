# Recepción de fruta embalada: aceptación e integración

Este PR depende de #394 (`agent/recepcion-fruta-embalada-borradores`). Usa sus migraciones, captura y catálogo reales, sin tablas sustitutas para pruebas. Orden de integración: captura → aceptación → documentos y etiquetas.

## Contrato de captura

El encabezado usa `cliente_id` global, `recepcion_at`, estado y versión. El detalle usa `recepcion_fruta_embalada_id`, `articulo_validacion_id`, `origen_validacion_id`, snapshots comerciales y `temperatura_pulpa_c`. Los umbrales globales están en `umbrales_prefrio_especies.temperatura_maxima_c`, asociados por nombre de especie normalizado. La aceptación vuelve a validar artículos, orígenes, combinaciones, asociación variedad/CSG y SAG activos, y exige revisar cualquier cambio en la captura o el umbral.

## Acciones en Oficina y PDA

- `GET /api/recepciones-fruta-embalada/{uuid}/aceptacion`: revisión y versión en borrador; snapshot, folios, advertencias e incidencias después de aceptar.
- `POST .../aceptar`: `{operacion_id, version}`. Acceso de gestión de recepción, con habilidad de tablet del módulo correspondiente.
- `POST .../anular`: `{operacion_id, motivo}`. Administrador o supervisor de frío con acceso al módulo. Impide anular después de movimientos, ubicación, prefrío, repaletizaje, reserva de carga o trabajo físico iniciado.

Ambas pantallas permiten aceptar el borrador guardado y muestran el destino, folio interno, advertencias e incidencias. La anulación exige motivo. Los reintentos conservan `operacion_id`. Ambas acciones bloquean temporada y recepción, incrementan la versión y registran antes/después con usuario y dispositivo en la auditoría de captura. Una falla revierte folios, habilitaciones, planes y tareas.

## Folios y condición térmica

Decisión del usuario: cualquier choque con un folio activo **o histórico** asigna uno interno. Se conserva la unicidad global del número. La referencia original queda en `identificador_externo` y los datos de origen en `datos_externos`. El prefijo configurable `FOLIOS_PREFIJO_RECEPCION_EXTERNA` es INT por defecto; la secuencia de planta es global, de diez dígitos, sin reiniciarse por temporada y omitiendo números ocupados.

Las referencias externas pueden repetirse entre pallets y recepciones. La columna calculada de integración conserva la unicidad de las demás fuentes. Antes de revertir esta migración deben resolverse las referencias externas repetidas, incompatibles con el índice anterior.

Prefrío declarado y temperatura ≤ umbral: habilitación `prefrio_origen` y tarea de ubicación inicial de prioridad alta. Los saldos siguen el criterio térmico y quedan fuera del planificador. Sin prefrío o sobre umbral: pendiente de prefrío; si se declaró prefrío se abre incidencia con lectura y umbral. Sin umbral se respeta lo declarado y se guarda advertencia. Se genera el objetivo rolling y se mantienen los controles del planificador existente. Consultas muestra planta, guía, CSP y referencia externa.

## Prueba física pendiente

Registrar y aceptar el camión de seis pallets del PR 1; comprobar folios, SAG, temperaturas, incidencia y tareas en Operación ahora/Consultas. Estas operaciones no se han realizado en el servidor de planta.
