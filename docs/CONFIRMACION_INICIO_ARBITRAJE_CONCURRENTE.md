# Confirmación de folio/PIN y arbitraje concurrente

## Incidente observado

El registro del servidor de pruebas del 28 de octubre de 2026 muestra un error MySQL 1062 al insertar `ciclos_arbitraje_maniobras.snapshot_version`, invocado desde `PlanOperacionalController::iniciar`. El camarero había confirmado folio/PIN; la excepción interrumpía la autorización del inicio y la tablet mostraba `Server Error`. Un nuevo intento podía funcionar.

Dos peticiones pueden evaluar el mismo estado y compartir la misma huella del planificador. La restricción única evita dos ciclos para esa huella. El código ya intentaba recuperar el ciclo ganador, pero una transacción exterior con aislamiento REPEATABLE READ podía conservar una vista anterior: MySQL rechazaba el duplicado contra la fila ya confirmada por otra conexión y la consulta normal de recuperación todavía no la veía. Tampoco bastaba recuperar solo el ciclo con una lectura actual si sus decisiones seguían consultándose desde la vista antigua.

La recuperación realiza una lectura actual, limitada a esa huella, del ciclo existente y de sus decisiones. Conserva la restricción única, la transacción exterior y las validaciones del inicio. No borra ciclos, no registra movimientos adicionales y no transforma otros errores SQL en resultados exitosos.

## Validación

La regresión usa dos conexiones MySQL reales y una transacción exterior REPEATABLE READ. La primera conserva una vista en la que el ciclo todavía no existe; la segunda confirma el ciclo y sus decisiones. Se comprueba que el arbitraje reutiliza esos mismos registros y que la API de inicio acepta folio/PIN en la primera petición, conserva responsable y dispositivo, registra la confirmación y no inventa un movimiento físico. Las consultas normales de la primera conexión verifican previamente que el ciclo sigue invisible en su snapshot.

## Configuración del servidor de pruebas

El mismo archivo de log también contiene `Unknown database 'laravel'`, durante autenticación y registro de excepciones en Telescope. Es un fallo diferente al duplicado del planificador; el nombre `laravel` coincide con el valor por defecto si no se resuelve la configuración operativa. El log por sí solo no determina si proviene de configuración en caché, variables heredadas o de otro proceso web.

En el proyecto del servidor de pruebas, consultar en Tinker únicamente:

```php
dump(['bd_real' => DB::connection()->getDatabaseName(), 'config_cache' => app()->configurationIsCached(), 'entorno' => app()->environment(), 'env_archivo' => app()->environmentFilePath(), 'php' => PHP_BINARY]);
```

La conexión debe ser `folios_pruebas`. La verificación CLI no acredita por sí sola qué configuración usa cada petición HTTP. Si la instalación muestra la base correcta en CLI y persisten peticiones a `laravel`, comprobar el proceso que atiende la URL de la tablet y su instalación, generar la caché con `php artisan config:cache` desde el proyecto verificado y reiniciar ese proceso web y sus workers. En un despliegue, generar esa caché después de `optimize:clear`. La base oficial es `estiba_wms`, en otra instalación.

No crear una base `laravel` para ocultar el error ni cambiar credenciales para hacer coincidir un proceso equivocado. La corrección de arbitraje no reemplaza esta comprobación de configuración.

## Despliegue y aceptación

El cambio es de servidor. No añade migraciones ni requiere OTA/APK. Actualizar el backend, limpiar y reconstruir su caché de configuración una vez verificada la conexión y reiniciar workers. Las migraciones y recursos de otros PR incluidos en el despliegue deben tratarse según su alcance.

Repetir la confirmación de folio/PIN con dos tablets y con el worker activo. Verificar inicio único, conservación de sus destinos y movimientos, ausencia del error 1062 indicado y conexión estable a `folios_pruebas`. Un folio/PIN incorrecto mantiene el rechazo de validación habitual. Si una entrega ya quedó pendiente en la tablet, revisar su resultado con el mismo identificador de operación antes de repetir su registro.
