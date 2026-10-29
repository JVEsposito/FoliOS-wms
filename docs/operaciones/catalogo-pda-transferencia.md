# Descarga recuperable del catálogo PDA

La PDA nativa negocia `GET /api/validacion/catalogos?formato=fragmentos-v1`.
La representación anterior se conserva para aplicaciones antiguas y web. Si un backend anterior entrega el catálogo completo, la PDA lo acepta y usa su ETag durante el resto de esa ejecución de la app.

## Publicación y recuperación

- Un manifiesto identifica una temporada y versión, sus cantidades y los SHA-256 de sus partes. Los artículos y combinaciones se agrupan de forma estable usando el primer byte del UUID módulo 32; categorías y orígenes tienen un grupo cada uno.
- El servidor lee la proyección bajo el bloqueo compartido de temporada. Libera la transacción antes de codificar y guardar las partes. Publica el manifiesto solo después de almacenar todas las partes correctamente.
- La caché usa el almacén Laravel configurado. Debe ser persistente y compartido entre los workers/instancias que atiendan las solicitudes; el almacén `database` predeterminado cumple este requisito. `array` se utiliza solo en las pruebas.
- La preparación tiene un único generador por temporada. Las otras solicitudes reciben 503 con `Retry-After: 3`, sin esperar dentro del worker. El sondeo de la PDA reintenta; el usuario también puede tocar el indicador de sincronización.
- El manifiesto dura seis horas y las partes un día. Se retienen tres versiones por temporada y se retiran las partes que ninguna utiliza. Una descarga cuyo manifiesto venció recibe 409; en el siguiente intento obtiene el índice vigente y reutiliza sus partes válidas.
- La PDA guarda partes verificadas por servidor/equipo en archivos privados. Una interrupción conserva las partes completas, el catálogo vigente y las capturas. La limpieza de partes antiguas no toca las bandejas ni las jornadas.
- Para una parte cambiada, la PDA puede enviar `?base=SHA256`. Si el servidor conserva esa base, calcula altas/cambios y retiros por ID. Envía la actualización por filas cuando pesa menos que la parte completa; si no existe la base, entrega la parte completa.
- La PDA reconstruye la representación canónica y verifica el SHA-256 de destino incluso cuando recibe cambios por filas. Comprueba cantidades, duplicados y relaciones antes de instalar el catálogo. El guardado completo y su respaldo siguen el mecanismo del PR #540.

## Tráfico y experiencia de uso

La primera descarga aún necesita todos los datos operativos. El diccionario reduce el JSON que procesa el equipo; dividirlo no garantiza reducir el tráfico comprimido. Las actualizaciones posteriores reutilizan partes y pueden transferir solo filas modificadas.

El catálogo sin cambios devuelve 304 sin cuerpo. El envío de capturas y los historiales avanzan independientemente de la descarga. Con una copia local, la pantalla permite trabajar mientras actualiza el catálogo y conserva el folio en curso. Sin copia, muestra el avance antes de habilitar la validación.

El sondeo del catálogo y la bandeja continúa cada 30 segundos. Los historiales se consultan cada dos minutos, al volver a primer plano, al sincronizar manualmente y después de enviar capturas. Registrar una validación sigue actualizando la sesión inmediatamente. Las solicitudes tienen un límite de 45 segundos; un envío con respuesta perdida conserva su UUID para el reintento idempotente.

En las herramientas de red de la PDA se puede revisar:

| Respuesta | Dato |
| --- | --- |
| Manifiesto 200 | `X-Catalog-Bytes`: suma de bytes JSON de todas las partes completas, antes de compresión |
| Manifiesto 200 | `X-Catalog-Fragments`: número total de partes |
| Manifiesto 200 | `Server-Timing`: tiempo del servidor para obtener/preparar el manifiesto |
| Parte 200 | `X-Catalog-Bytes`: bytes JSON de esa respuesta, completa o por filas |
| Parte 200 | `X-Catalog-Target`: hash de la parte completa reconstruida |
| Catálogo sin cambios | 304 sin cuerpo; no debe repetir partes |

El tamaño transferido que muestre la herramienta de red puede incluir compresión y cabeceras. No es equivalente a `X-Catalog-Bytes`. Para comparar, registrar tanto el tamaño transferido como el descomprimido, duración, estado HTTP, temporada y versión; no hace falta exportar tokens de autorización.

## Comprobación en planta

Integrar primero #540 y después este cambio. Actualizar backend y luego el código móvil manteniendo los datos de la instalación. No requiere migraciones ni dependencias nativas nuevas.

1. Descargar por primera vez y comprobar cantidades/versiones contra Oficina.
2. Interrumpir la conexión durante la descarga, cerrar y abrir la app, reconectar y comprobar que reutiliza partes y mantiene capturas pendientes.
3. Cambiar un envase en Oficina, incluyendo un caso con múltiples artículos afectados. Revisar las respuestas por filas y confirmar que desaparecen los IDs retirados sin sustituir selecciones de la captura en curso.
4. Repetir una sincronización sin cambios: esperar 304 sin volver a descargar el catálogo.
5. Confirmar que el historial lento no bloquea el catálogo ni el envío de capturas y que la PDA indica la actualización pendiente si falla.

Las cifras de las pruebas con 29.676 artículos y 37.184 combinaciones son simulaciones. El tiempo, memoria máxima y tráfico comprimido en los equipos de planta deben medirse con su catálogo real.
