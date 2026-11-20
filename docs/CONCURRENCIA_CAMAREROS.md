# Concurrencia de camareros en labores dirigidas

Las tablets actualizadas abren una sesión vinculada al paso actual, al operador, al dispositivo y a la cámara. Dos labores independientes pueden operar en una cámara; cada una conserva el claim del pallet, la reserva del destino y el acceso a su banda/nivel. Una banda comprometida, una posición ocupada o un pallet delante siguen rechazándose. La edición manual conserva su sesión exclusiva y respeta las reservas de las labores.

El contrato `recursos_v1` valida la propuesta contra los recursos actuales dentro de la transacción. Un movimiento ajeno en otra banda deja de invalidar toda la frontera por el hash o la versión global de la cámara. Se mantienen versiones de tarea/plan, permisos, temporada, arbitraje vivo, validación del paso actual e idempotencia de movimientos. Los clientes anteriores conservan la validación global estricta.

La tablet recupera el destino propio ante una respuesta perdida y vuelve a leer/calcular hasta dos veces ante conflictos transitorios. No reintenta automáticamente un movimiento físico. Los conflictos reales de acceso o reserva siguen requiriendo otra propuesta o intervención.

Las sesiones de tarea cierran al completar o liberar el trabajo. Una tarea en proceso mantiene sus recursos hasta completar o regularizar el pallet; cerrar la pantalla o forzar el cierre de sesión no los libera.

La comprobación de reservas vencidas antes de un movimiento solo bloquea las reservas
de las tareas del pallet y la clave del destino concreto. Se evitan recorridos globales
de reservas activas mientras se retiene la cámara: podían bloquear el claim ajeno de un
camarero que, al liberarlo, necesitaba actualizar esa misma cámara. Una prueba con tres
procesos mantiene bloqueada una reserva ajena hasta que los otros dos validan sus
movimientos y el acceso a las posiciones de frente en bandas independientes. La lectura
de ese acceso utiliza el índice de la posición reservada, sin recorrer las reservas de
otras bandas. El escenario completo conserva los 84 movimientos.

## Despliegue

Ejecutar la migración `2026_10_30_120000_habilitar_sesiones_estiba_por_tarea`, reconstruir web y reiniciar procesos con el procedimiento habitual. Después publicar el mismo código móvil en `production` y `camaras-pruebas`. No cambia el runtime 1.5.0 ni requiere APK nuevo. El servidor acepta tablets previas durante la transición; la concurrencia requiere la actualización móvil.

## Comprobación operacional

Con dos usuarios y dispositivos distintos, asumir labores con pallets y bandas diferentes de una misma cámara, iniciar ambas y confirmar los dos movimientos. Comprobar que un destino compartido o una banda comprometida se rechaza; que finalizar una labor no cierra la sesión de la otra; y que las sesiones previas de edición manual conservan sus controles.

## Inicio de movimientos: lectura acotada al camarero

La comprobación de «otro pallet físicamente en movimiento» utiliza el índice existente `tareas_responsable_estado_idx` por responsable y estado. El paso actual todavía está asumido, por lo que no se necesita excluir su ID. Esa desigualdad permitía que MySQL eligiera un recorrido global de tareas; su lectura bloqueante alcanzaba filas ajenas y podía cruzarse con el incremento de revisión de una cámara al liberar otra reserva.

Se conserva la lectura actual con bloqueo y el mutex del usuario: un camarero sigue sin poder iniciar dos pallets desde tablets distintas. La prueba de aislamiento mantiene bloqueada una tarea en proceso de un operador mientras los otros dos inician sus propios retiros; comprueba que ninguno necesite la fila ajena. La prueba completa de tres camareros conserva los 84 movimientos, liberaciones, retomas, vencimientos y verificaciones contra intrusiones de otra tablet. No requiere migraciones ni cambios móviles.
