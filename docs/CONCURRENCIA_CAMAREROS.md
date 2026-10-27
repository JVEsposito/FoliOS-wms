# Concurrencia de camareros en labores dirigidas

Las tablets actualizadas abren una sesión vinculada al paso actual, al operador, al dispositivo y a la cámara. Dos labores independientes pueden operar en una cámara; cada una conserva el claim del pallet, la reserva del destino y el acceso a su banda/nivel. Una banda comprometida, una posición ocupada o un pallet delante siguen rechazándose. La edición manual conserva su sesión exclusiva y respeta las reservas de las labores.

El contrato `recursos_v1` valida la propuesta contra los recursos actuales dentro de la transacción. Un movimiento ajeno en otra banda deja de invalidar toda la frontera por el hash o la versión global de la cámara. Se mantienen versiones de tarea/plan, permisos, temporada, arbitraje vivo, validación del paso actual e idempotencia de movimientos. Los clientes anteriores conservan la validación global estricta.

La tablet recupera el destino propio ante una respuesta perdida y vuelve a leer/calcular hasta dos veces ante conflictos transitorios. No reintenta automáticamente un movimiento físico. Los conflictos reales de acceso o reserva siguen requiriendo otra propuesta o intervención.

Las sesiones de tarea cierran al completar o liberar el trabajo. Una tarea en proceso mantiene sus recursos hasta completar o regularizar el pallet; cerrar la pantalla o forzar el cierre de sesión no los libera.

## Despliegue

Ejecutar la migración `2026_10_30_120000_habilitar_sesiones_estiba_por_tarea`, reconstruir web y reiniciar procesos con el procedimiento habitual. Después publicar el mismo código móvil en `production` y `camaras-pruebas`. No cambia el runtime 1.5.0 ni requiere APK nuevo. El servidor acepta tablets previas durante la transición; la concurrencia requiere la actualización móvil.

## Comprobación operacional

Con dos usuarios y dispositivos distintos, asumir labores con pallets y bandas diferentes de una misma cámara, iniciar ambas y confirmar los dos movimientos. Comprobar que un destino compartido o una banda comprometida se rechaza; que finalizar una labor no cierra la sesión de la otra; y que las sesiones previas de edición manual conservan sus controles.
