# Errores persistentes en Labores de camareros

La recarga de la bandeja borraba el error que acababa de informar una maniobra. Se reemplaza el aviso por un diálogo que permanece hasta pulsar **Entendido** o el botón Atrás de Android. Los refrescos, incluso si fallan, conservan el mensaje que el operador está leyendo. Textos extensos tienen desplazamiento y no se recortan.

Una cámara de origen o destino en uso muestra su nombre, operador y dispositivo cuando el plano los informa. Indica pedir al operador que termine y cierre su sesión, o solicitar revisión a un supervisor si quedó abierta. Luego se puede volver a intentar. También cubre la apertura concurrente entre consultar el plano y solicitar la sesión.

El bloqueo de cámaras se conserva. No se cierran sesiones ajenas ni se reintenta automáticamente un movimiento. Los rechazos de folio/PIN continúan dentro de la pantalla de confirmación.

## Desplegar

1. Tras fusionar, descargar `main`. Este cambio no agrega migraciones ni código de backend o de Oficina.
2. Actualizar el cliente móvil que ejecuta Labores. El perfil `apk-camaras` usa el canal `production`; publicar una actualización en ese canal llega también a los demás equipos compatibles que lo usan. El canal `pda-pruebas` corresponde a la APK PDA de pruebas, no a esa tablet. Confirmar la distribución instalada antes de publicar la OTA.
3. Reabrir la app y comprobar que recibió la actualización compatible con su runtime. Compilar los assets de Oficina por sí solo no modifica la tablet.

La dependencia nueva `react-test-renderer` se utiliza únicamente para pruebas y no requiere módulos nativos nuevos.

## Prueba en el servidor 8001

1. Desde un segundo usuario/dispositivo, abrir la sesión de estiba de la cámara de destino.
2. En la tablet de camareros, tomar una maniobra de recepción de túnel e intentar iniciar el movimiento.
3. Comprobar nombre de cámara, operador/dispositivo y orientación. Esperar al menos un minuto: debe permanecer el mismo mensaje, incluso tras refrescos.
4. Pulsar Entendido. La tarea conserva el estado enviado por el servidor y la sesión ajena sigue abierta.
5. Cerrar la cámara desde el dispositivo dueño; volver a iniciar la misma tarea y confirmar el movimiento.
6. Repetir con un rechazo de ubicación y comprobar que el error permanece. Para PIN/folio incorrectos se conserva la corrección dentro de la confirmación.

## Validación automática

En `mobile`, ejecutar `npm ci`, `npm run test:catalog`, `npm run typecheck` y `npm run export:android`. La prueba de componentes monta la bandeja real con React, simula las respuestas del servidor y verifica persistencia, concurrencia, reintento, PIN, movimientos rechazados y textos largos.
