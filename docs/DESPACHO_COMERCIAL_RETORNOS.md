# Despacho comercial de retornos de Packing

La pantalla `/oficina/materia-prima/despacho-comercial` controla la salida de bins físicos que regresaron de Packing. Acepta todas las clasificaciones registradas por Cuadraturas, incluidos precalibre, comercial, descarte y otros resultados. Solo ofrece bins **regularizados de la temporada activa**, con folio y kilos definitivos, que no estén anulados ni reservados.

## Operación

1. Seleccionar destinatario y uno o más bins. Al crear el borrador se reservan esos bins y se asigna un número interno `DC-*`. El borrador no representa una salida física.
2. Emitir la **guía electrónica de despacho en el Portal SII**, fuera de FoliOS.
3. Introducir en FoliOS únicamente el **número de la guía SII ya emitida** y confirmar la salida. Un número de guía solo puede estar asociado a un despacho en la misma temporada.
4. Consultar el historial, imprimir el comprobante **interno** o descargar la planilla CSV. El comprobante indica explícitamente que no reemplaza el DTE.

Si la salida no se realiza, cancelar el borrador libera sus bins. Una salida confirmada no se cancela desde esta pantalla, porque la guía emitida en SII y una eventual rectificación tienen su propio trámite. Las correcciones y anulaciones del retorno quedan bloqueadas mientras el bin esté reservado o despachado, para no alterar el detalle de la salida.

El despacho conserva por bin un snapshot de folio, clasificación y kilos definitivos. La tabla de bins mantiene la asignación activa para que el mismo bin no se despache dos veces. Los borradores cancelados conservan historial pero liberan esa asignación.

En el reporte de existencias MP, los bins reservados aún figuran físicamente con estado de reserva. Los bins de despachos confirmados dejan de figurar como existencia disponible.

## Acceso

Administradores, supervisores de frío y digitadores de materia prima con el módulo `materia-prima.despacho-comercial` pueden preparar y confirmar despachos. Los perfiles de solo consulta pueden revisar el historial. La migración habilita el módulo en perfiles **predeterminados** de esos roles; los perfiles personalizados deben habilitarlo desde Accesos y Temporadas. El acceso a la vista no sustituye la verificación de permisos de la API.

## Despliegue

Ejecutar `php artisan migrate --force`, construir los recursos web (`npm run build`) y limpiar las cachés de Laravel según el procedimiento habitual. No requiere actualización de la APK: es un módulo de Oficina.
