# Worker y scheduler permanentes en Windows

Estas tareas se instalan en el equipo Windows que ejecuta FoliOS con Laragon. El servicio de MySQL debe arrancar automáticamente antes de esperar que la cola procese trabajos; estos scripts no arrancan Laragon ni MySQL.

## Instalar

Abra PowerShell como administrador **con la misma cuenta de Windows que utiliza Laragon y escribe en `storage`**. Desde el directorio del proyecto:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\windows\instalar-servicios.ps1 -Proyecto 'C:\laragon\www\estiba-wms'
```

`-Php 'C:\laragon\bin\php\php-8.3.30\php.exe'` es opcional; sin él se selecciona la versión más reciente de `C:\laragon\bin\php`. La instalación actualiza las dos tareas existentes si vuelve a ejecutarse. Por defecto usa la identidad de la ventana PowerShell. **Si «Ejecutar como administrador» abrió otra cuenta** (por ejemplo `EQUIPO\admin` en lugar de `EQUIPO\despacho`), pase explícitamente `-Usuario 'EQUIPO\despacho'`: obtenga ese nombre con `whoami` en una terminal normal y confirme la cuenta con `estado-servicios.ps1`. Sin este parámetro, volver a instalar desde la cuenta administradora cambia ambas tareas a esa cuenta.

Las tareas usan S4U, sin almacenar una contraseña, y pueden correr sin sesión iniciada. La cuenta necesita permiso para ejecutar PHP, acceder al proyecto y escribir en `storage`, `bootstrap/cache` y la base de datos local. Si la base de datos está en **otro equipo** y requiere credenciales de red de Windows, configure el principal de la tarea con `LogonType Password` y una cuenta apropiada; S4U no ofrece credenciales de red. No use SYSTEM por defecto: podría dejar archivos que Apache no pueda modificar.

La tarea **FoliOS Worker** arranca al iniciar Windows y tiene un disparador cada cinco minutos. Su script vuelve a ejecutar `queue:work` cinco segundos después de una salida normal o de un fallo. `MultipleInstances=IgnoreNew` evita que los disparadores acumulen instancias. Las salidas y reinicios se anotan en `storage\logs\worker-servicio.log`; las excepciones de los trabajos van al log de Laravel. La tarea **FoliOS Scheduler** ejecuta `artisan schedule:run` cada minuto, también con `IgnoreNew`. Ambas tienen tiempo de ejecución ilimitado y reintentos al fallar.

## Verificar

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\windows\estado-servicios.ps1 -Proyecto 'C:\laragon\www\estiba-wms'
php artisan schedule:list
php artisan queue:failed
```

El estado muestra el estado de la tarea, última ejecución, último resultado y los latidos del worker y scheduler junto al arbitraje. Espere dos minutos desde la instalación o reinicio antes de evaluar umbrales. `0` como último resultado del Scheduler indica que `schedule:run` terminó normalmente. El Worker permanece en estado `Running` mientras sirve la cola.

También puede consultar `/api/administracion/planificador/salud` con una cuenta autorizada y ver los avisos en **Operación ahora**. Los latidos se guardan en la caché configurada por Laravel, sin dependencias adicionales cuando `CACHE_STORE=database`. Un worker en reposo sigue marcando latido en cada vuelta. Umbrales predeterminados: worker 120 s y scheduler 150 s; se pueden ajustar con `WMS_WORKER_HEARTBEAT_STALE_SECONDS` y `WMS_SCHEDULER_HEARTBEAT_STALE_SECONDS`. Un latido ausente indica que todavía no se observó ese proceso; uno vencido señala que dejó de marcar, aunque la tarea de Windows aparezca registrada.

**Después de cada despliegue:**

```powershell
php artisan optimize:clear
php artisan queue:restart
```

El worker sale ordenadamente y el bucle del script lo relanza con el código nuevo. Si cambia la ubicación del proyecto o PHP, vuelva a ejecutar el instalador con los parámetros actualizados. Mientras la tarea esté instalada, no hace falta abrir `queue:work` en una terminal; hacerlo deja un worker adicional fuera del control de la tarea.

## Problemas y prueba manual

- Si ambos latidos faltan: compruebe que MySQL inició, revise `Get-ScheduledTaskInfo` y el historial de las tareas en el Programador de tareas. Verifique permisos de la cuenta para iniciar sesión como trabajo por lotes.
- Si solo falla el worker: revise `storage\logs\worker-servicio.log`, `storage\logs\laravel.log`, `php artisan queue:failed` y la conexión `QUEUE_CONNECTION=database`.
- Si solo falla el scheduler: ejecute manualmente `php artisan schedule:run`, revise la ruta a `php.exe` y compruebe la repetición cada minuto en el Programador de tareas. `schedule:run` puede terminar sin error aunque una tarea programada falle; revise `laravel.log`.
- Si el sistema informa detenido pese a tareas en ejecución: revise `CACHE_STORE=database`, conexión a MySQL, zona horaria y reloj del servidor. El estado de procesos mide la última escritura de caché, no la existencia de un PID.

Tras instalar en **servidor de pruebas**, cierre las terminales anteriores, compruebe los dos latidos y termine el proceso `php.exe` correspondiente al worker. Debe reiniciarse por el script o, si se detuvo la tarea completa, por el próximo disparador en cinco minutos. Verifique el aviso en Operación ahora durante la interrupción. Reinicie Windows sin iniciar sesión y espere al menos dos minutos para comprobar los latidos y que la tablet vuelva a recibir labores. No realice esa prueba de interrupción durante la operación real.

Para comprobar la sintaxis de los scripts en una máquina con PowerShell:

```powershell
.\scripts\windows\verificar-sintaxis.ps1
```

## Desinstalar

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\windows\desinstalar-servicios.ps1
```

Desinstalar detiene las tareas registradas y elimina sus disparadores. Si queda un proceso `php.exe` fuera del Programador de tareas, termínelo por separado después de identificar su línea de comandos.
