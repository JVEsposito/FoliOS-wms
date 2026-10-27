# Disponibilidad de camareros en Operación ahora

La tablet conectada informa su presencia al entrar al espacio de operación y cada 15 segundos mientras está en primer plano. La identidad procede del token y del dispositivo autenticados; no se acepta un usuario indicado en el cuerpo. Requiere el permiso para operar cámaras de productos.

La presencia pertenece a la persona. Una labor activa en cualquiera de sus dispositivos impide anunciarla disponible. Los cambios de tareas, maniobras y custodias actualizan la disponibilidad después del commit, para no anunciar como finalizada una operación que termine en rollback. Una maniobra pausada o todavía abierta se muestra en espera. Al terminar la última labor, el usuario conectado pasa a Disponible y el servidor genera un identificador único para ese aviso.

Oficina consulta un feed específico cada 5 segundos; el resto del tablero conserva su intervalo normal. El contador y la lista mantienen visibles a los disponibles aunque ya no tengan tareas. Se muestra un aviso por transición; abrir el tablero, reconectar o cambiar de temporada establece una línea de base y no repite avisos antiguos. El aviso se ve con Oficina abierta y conectada: no es una notificación push del sistema operativo.

Tras 75 segundos sin latidos, el usuario se muestra sin conexión y deja de contarse como disponible. Salir de la sesión lo desconecta inmediatamente. Una pérdida de comunicación del tablero muestra disponibilidad sin confirmar y conserva la hora de la última lectura. Las tablets anteriores sin presencia pueden conservar tareas visibles, sin declararse libres. Los GET no escriben estados ni disparan replanificación.

## Despliegue y prueba

Ejecutar la migración `2026_10_30_140000_crear_presencias_camareros`, reconstruir web y reiniciar los procesos según el procedimiento habitual. Publicar la actualización móvil en `production` y `camaras-pruebas`; no cambia el runtime 1.5.0 ni requiere otro APK.

Con Operación ahora abierto, entrar con un camarero, asumir una labor y terminarla. Confirmar que aparece Disponible, se conserva en la lista y el aviso no se repite cada cinco segundos. Repetir con otra labor pendiente y con una maniobra pausada: debe mantenerse ocupado/en espera. Cerrar sesión o dejar la tablet sin conexión y confirmar que ya no aparece disponible.
