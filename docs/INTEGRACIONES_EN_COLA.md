# Consultas externas en segundo plano

Oficina solicita muestras, estructura, columnas, pruebas de conexión, pruebas/guardados
de mapeo y «Leer ahora» mediante `Prefer: respond-async`. El servidor responde **202**
con un identificador; la pantalla consulta su estado con GET y muestra la fase real
(en cola, consultando, terminada o fallida). No reenvía el POST al sondear. Pendientes
mantiene disponibles sus tablas y búsquedas mientras espera. Al recargar, recupera el
estado de la lectura vigente. Oficina y PDA siguen leyendo la copia local.

Los consumidores anteriores que omitan `Prefer` conservan la respuesta síncrona de la
API. Las pantallas incluidas usan siempre la cola para las operaciones anteriores.
Crear/editar credenciales sigue siendo una escritura local rápida; nunca se incorpora
la contraseña al job. El comando programado también encola por defecto.

## Despliegue obligatorio

1. Aplicar `php artisan migrate --force`, compilar `npm ci && npm run build` y ejecutar
   `php artisan optimize:clear`.
2. Mantener el scheduler existente (`php artisan schedule:run` cada minuto).
3. Instalar **un proceso exclusivo** con reinicio automático:

   ```sh
   php artisan queue:work integraciones --queue=integraciones --sleep=1 --tries=1 --timeout=180 --max-time=3600
   ```

La conexión `integraciones` usa la tabla `jobs` de la BD operacional, con cola
`integraciones`, aunque la cola general esté configurada como `sync`. El proceso de
tareas operativas continúa atendiendo su cola habitual. No incluir `integraciones` en
ese proceso. Solicitud y job se guardan en la misma transacción de base de datos.

Ejemplo Supervisor (ajustar ruta, usuario y PHP de la instalación):

```ini
[program:folios-integraciones]
directory=/var/www/folios
command=/usr/bin/php artisan queue:work integraciones --queue=integraciones --sleep=1 --tries=1 --timeout=180 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=240
redirect_stderr=true
stdout_logfile=/var/log/folios-integraciones.log
```

En Windows, crear otro servicio con el mismo comando y directorio de FoliOS, usando el
PHP que tiene `pdo_mysql`; configurar reinicio tras salida/fallo. Laravel aplica el
timeout del worker mediante `pcntl` en Linux. Windows requiere supervisión del proceso
para consultas/controladores que no respeten límites; verificar el watchdog del servicio
en ese servidor. El conector intenta limitar cada SELECT en MySQL (MAX_EXECUTION_TIME)
y MariaDB (max_statement_time); servidores antiguos pueden no admitirlos.

Después de actualizar código, reiniciar este proceso con el supervisor/servicio.
Sin el proceso, el estado queda **en cola**, visible en pantalla; no se ejecuta una
consulta lenta como sustitución dentro de HTTP. `retry_after=300` supera el timeout180.
No hay reintento automático de una consulta: el usuario puede solicitar otra después
de un fallo. Para diagnóstico manual existe `integraciones:leer-stock-origen --sin-cola`.

## Integridad, duplicados y privacidad

- Las solicitudes idénticas pendientes/en curso comparten operación. El stock se
  deduplica por fuente y versión, entre usuarios y scheduler; muestras administrativas
  solo entre solicitudes del mismo usuario. Un job entregado otra vez no repite su consulta.
- Antes de consultar se revalidan conexión, versión y permisos. Al publicar stock/mapeo,
  se bloquea y verifica la configuración vigente y que la operación siga activa.
- Una copia de stock se publica completa, dentro de una transacción. Error, vacío,
  mapeo inválido, cambio de configuración o proceso interrumpido conservan la anterior.
  La pantalla mantiene fecha, antigüedad, vigencia y fallo de la última lectura.
- La entrada y el resultado temporal se cifran con APP_KEY. La cola contiene solo el
  UUID de operación. No se guardan filas de muestra en auditoría ni en localStorage.
  El resultado deja de estar disponible al cumplirse cinco minutos; la limpieza del
  scheduler borra su contenido. Los metadatos duran siete días. Conservar APP_KEY.
- Cada minuto se vencen operaciones sin terminar durante quince minutos; un proceso
  que responda después no puede publicar. La limpieza requiere el scheduler operativo.
- El endpoint de estado exige sesión y permiso; las muestras solo las ve su solicitante.
  El stock y su estado pueden consultarse con permiso de validaciones.

## Fuente de planta

«Ver muestra» y la simulación del mapeo disponen de **60 segundos por SELECT de datos**
cuando se ejecutan en el worker de Integraciones. Antes conservaban un límite de 15 s,
inferior a los 16,71 s observados al consultar un pallet de `odbc_stock`. Se puede ajustar
`WMS_ORIGEN_MUESTRA_SEGUNDOS` entre 15 y 120; un valor inválido usa 60. Las consultas de
estructura/columnas y las muestras de consumidores síncronos conservan sus 15 s.
El presupuesto de muestra deja margen bajo el timeout de 180 s del worker. No cambia
el timeout de conexión de 5 s ni el presupuesto de la lectura completa de stock (60 s).
Después de cambiar código o configuración, ejecutar `php artisan optimize:clear` y
reiniciar el worker exclusivo para que cargue los nuevos valores.

El mapeo permite filtros de igualdad de empresa, temporada y bodega usando las columnas
descubiertas; no fija CAP ni agrega filtros ocultos. `Pallet` es el número del pallet,
incluidos ceros iniciales. El campo opcional **Disponibilidad en origen** conserva NULL
como dato por confirmar y el texto entregado por planta; no decide disponibilidad
operativa ni transforma NULL en cero o falso.

La cola aísla la espera; no reduce por sí misma los 1–17 segundos observados en origen.
La revisión de TI sobre `odbc_stock` (vista de lectura con campos necesarios, plan,
índices y conversiones como `12.51%`) sigue siendo necesaria para acelerar la consulta.

## Comprobación en la instalación

Solicitar dos veces la misma lectura: debe aparecer un solo trabajo activo. Mientras
el origen tarda, buscar pendientes y consultar desde PDA. Detener/reiniciar el proceso
y comprobar estado visible. Simular vacío/fallo y un cambio de mapeo durante la lectura:
ninguno debe sustituir la última copia válida. Confirmar finalización y actualización
de fecha tras una lectura correcta. Medir con el catálogo y servidor reales; no se
promete un tiempo de respuesta del servidor externo sin esas mediciones.
