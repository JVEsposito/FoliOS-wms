# Recuperación SAG y reservas históricas de sesiones

## Casos informados el 28 de octubre de 2026

En `folios_pruebas`, el folio `1000000004` conserva una orden SAG de ubicación inicial creada el 20 de octubre. Su ubicación vigente en CAM-09, B03-P04-N1, se registró el 21 de octubre. La orden no tiene movimientos propios ni custodias temporales, pero sigue en proceso, instruye retirar desde TUNEL 6 y conserva una reserva en CAM-03, banda 2, nivel 1. La recuperación anterior exigía que la ubicación precediera a la creación de la orden y omitía este caso.

El folio `1000000118` tiene una labor pendiente de despeje en CAM-11 y tres pares de reservas de sesiones cerradas para las bandas 3 y 4. Todas están liberadas. Al asumir, se intentaba reactivar también ese historial: la segunda reserva de una misma banda chocaba contra la primera, de la propia maniobra, y se respondía que otra maniobra la tenía comprometida. La única reserva activa del diagnóstico pertenece al SAG en CAM-03; no justifica bloquear CAM-11.

## Corrección

Las reservas creadas al abrir una sesión de tarea conservan su ciclo de vida en esa sesión. Asumir una maniobra solo vuelve a adquirir sus reservas estructurales, sin `sesion_estiba_id`. No reactiva reservas de sesiones cerradas ni reclama destinos de intentos anteriores. Abrir una sesión nueva valida y reserva las bandas del recorrido vigente. Una reserva activa de otro propietario sigue impidiendo acceder a esa banda.

La recuperación SAG admite ubicaciones registradas después de crear una orden que todavía no inició. Para una orden en proceso, esa ampliación exige una ubicación estrictamente anterior a `iniciada_at`. Si falta esa fecha, coincide con la ubicación o es anterior a ella, se conserva la orden para revisión. Se mantienen los bloqueos por movimientos propios, custodia y maniobras no unitarias. La recuperación deja en el contexto las fechas de creación, ubicación e inicio, cancela por el circuito normal y conserva el destino SAG al generar el traslado desde la ubicación vigente. No registra un movimiento físico por recuperar la orden.

## Despliegue y comprobación

Estos cambios son del servidor. No agregan migraciones ni requieren OTA o APK. Actualizar el servidor de pruebas, limpiar cachés y reiniciar workers. El despliegue en el servidor oficial es independiente.

Antes de revisar los datos, comprobar la conexión real con `DB::connection()->getDatabaseName()`; en pruebas debe ser `folios_pruebas`. Una ruta fallida no debe permitir continuar un bloque de diagnóstico sobre otra instalación: encadenar el cambio de carpeta con `&&`.

1. Consultar la orden SAG con sus fechas `created_at`, `asumida_at` e `iniciada_at`, movimientos y custodias. La salida original no incluía las dos últimas fechas.
2. Ejecutar `php artisan sag:preparar-traslados`. Repetir debe conservar una única sustitución. Si la cronología permite recuperar, la orden anterior y su sesión se cierran por el circuito habitual; se publica un traslado desde CAM-09 con el mismo destino SAG. Si no la permite, no cambiar fechas ni retirar reservas manualmente: requiere revisión del movimiento físico.
3. Actualizar la bandeja de la tablet y tomar la tarea vigente. Una confirmación pendiente se revisa antes de continuar; no se repite el movimiento físico por reenviar un registro.
4. Retomar el despeje de `1000000118`: las reservas de las tres sesiones cerradas deben seguir liberadas y la sesión nueva debe reservar únicamente el recorrido vigente.
5. Comprobar también dos tareas en bandas distintas de la misma cámara y competencia real por una banda protegida: la primera debe permitir simultaneidad y la segunda debe conservar el rechazo.

No borrar tareas, reservas, movimientos ni capturas locales para aplicar esta corrección.
