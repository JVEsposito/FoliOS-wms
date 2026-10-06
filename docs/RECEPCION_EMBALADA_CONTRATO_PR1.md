# Recepción de fruta embalada: contrato de integración

Los PR de aceptación y documentos dependen de la captura que se está desarrollando por separado. No crean tablas de recepción, plantas ni el catálogo de umbrales en producción. Permanecen en borrador hasta integrar y probar la rama del PR 1. Los fixtures de `tests/Support/ContratoRecepcionEmbaladaPrueba.php` se usan exclusivamente para verificar el contrato en CI.

## Datos que aporta el PR 1

`plantas_origen`: `id` UUID, `nombre`, `codigo`, `activa` boolean.

`especies_validacion.umbral_prefrio`: decimal nullable, temperatura máxima de pulpa en °C. El maestro y su edición son del PR 1.

`recepciones_fruta_embalada`: `id`, `temporada_id`, `cliente_validacion_id`, `planta_origen_id`, `numero_guia`, `servicio` (`almacenaje`/`prefrio`), `estado` (`borrador`/`aceptada`/`anulada`), `llega_con_prefrio` boolean, `recibido_at`, `salida_at`, `turno`, `validador_id`, `chofer`, `rut_chofer`, `patente_delantera`, `patente_carro`, `observacion`, `created_at`, `updated_at`. Los IDs de catálogo son UUID; validador_id referencia el bigint de users.

`recepciones_fruta_embalada_pallets`: `id`, `recepcion_id`, `orden` entero positivo único dentro de la recepción, `folio_origen`, `tipo_bulto` (`pallet`/`saldo`), `especie_validacion_id`, `variedad_validacion_id`, `envase_validacion_id`, `calibre_validacion_id`, `csg_validacion_id`, `cantidad_cajas`, `csp`, `fecha_proceso_origen`, `temperatura_pulpa`, `condicion_sag_id` nullable, timestamps.

Si la rama del PR 1 usa otros nombres, se adapta `ContratoRecepcionEmbalada` y sus fixtures antes de fusionar. No se agregan sinónimos ni migraciones que modifiquen la captura a espaldas de su PR.

Las ediciones de encabezado y detalle del PR 1 deben bloquear el encabezado y verificar `estado=borrador` en la misma transacción. La aceptación bloquea temporada, encabezado y pallets, vuelve a validar el catálogo y compara la versión revisada. Esto evita aceptar mientras otro dispositivo modifica la captura.

## Acciones del PR 2

- `GET /api/fruta-embalada/recepciones/{uuid}/aceptacion`: revisión y versión si es borrador; snapshot, folios, advertencias e incidencias si ya se aceptó.
- `POST .../aceptar`: `{operacion_id, version}`. Permiso de validar pallets, en Oficina o PDA. Devuelve estado, folios, destino, plan e incidencias.
- `POST .../anular`: `{operacion_id, motivo}`. Permiso de corregir validaciones (administrador/supervisor). Cancela tareas reversibles e inactiva folios en una sola transacción. Impide anular si hubo movimiento, ubicación, prefrío, repaletizaje, reserva de carga o trabajo físico iniciado.

Clientes listos para conectar: `resources/js/shared/recepcion-embalada-actions.js` y `mobile/src/services/recepcionEmbaladaAcciones.ts`. Usar la versión de la última revisión para aceptar, deshabilitar el botón durante el envío y refrescar ante conflicto. Los reintentos conservan la operación hasta obtener respuesta. La captura y su navegación siguen a cargo del PR 1.

El PR 2 guarda auditoría/snapshots en `aceptaciones_fruta_embalada` y los enlaces en `recepcion_fruta_embalada_folios`; no modifica el esquema de las tablas del PR 1. `incidencias_recepcion_embalada` registra la temperatura y umbral de los prefríos declarados rechazados. Consultas presenta planta, guía, CSP y referencia externa.

## Folios y condición térmica

Decisión del usuario: el choque con un folio **activo o histórico** asigna uno interno. Se mantiene la unicidad global de numero_folio y la referencia original en identificador_externo. El prefijo configurable `FOLIOS_PREFIJO_RECEPCION_EXTERNA` es INT por defecto; la secuencia de planta es global y tiene diez dígitos, no se reinicia por temporada. Se omiten números ocupados.

Las referencias de recepción externa pueden repetirse entre pallets y recepciones. La columna calculada de integración conserva la unicidad de todas las demás fuentes. Para revertir la migración es necesario que no existan referencias externas repetidas, porque el índice original no las admitía.

Prefrío declarado y T° ≤ umbral: habilitación `prefrio_origen`, auditoría y tarea de ubicación inicial de prioridad alta para pallets. Los saldos quedan habilitados sin tareas. Sin prefrío o sobre umbral: pendiente de prefrío; sobre umbral declarado abre incidencia. Sin umbral: respeta lo declarado y guarda advertencia. La generación crea el objetivo rolling; su ejecución sigue los controles de guided/shadow/off del planificador existente.

## Integración y prueba de planta pendientes

Cuando se publique la rama del PR 1: integrar su esquema, conectar las acciones en sus pantallas de Oficina/PDA y ejecutar las pruebas contra sus migraciones reales, sin fixtures sustitutos. Después registrar y aceptar los seis pallets del camión de prueba, revisar el interno, SAG, temperaturas, incidencia, saldos y tareas en Operación ahora/Consultas. No se han ejecutado estas operaciones sobre el servidor de planta.
