# Temporada global activa única

La migración `2026_09_27_120000_restringir_temporada_activa_unica` crea la columna generada `activa_unica` y un índice único. Las temporadas inactivas generan `NULL`; MySQL permite muchas filas con ese valor, pero solo una fila puede generar `1`.

Antes de ejecutar `php artisan migrate` en una instalación existente, consultar:

```sql
SELECT codigo, id FROM temporadas WHERE activa = 1 ORDER BY codigo;
```

Si hay dos o más, la migración falla enumerando sus códigos. La decisión sobre cuál debe quedar activa se toma en Accesos; la migración no modifica temporadas. Ejecutar la migración cuando no haya activaciones simultáneas.

`ServicioTemporadaActiva` es scoped para cada petición o job. Las búsquedas normales reutilizan su resultado durante ese ciclo; las lecturas dentro de transacciones y las que adquieren bloqueos consultan siempre la BD. La activación olvida lo almacenado. Las consultas de inventario que necesitan evaluar la temporada dentro del mismo SELECT usan `subconsultaId()` del servicio.

Los filtros de `temporadas_materiales.activa` siguen siendo propios de Materiales. La lectura por ID de `ServicioCorreccionValidacionPallet` recupera datos históricos de la temporada asociada a una validación; tampoco selecciona la temporada activa.

## Concurrencia en el servidor de pruebas

Preparar **dos temporadas productivas** con fechas válidas y prefijos documentales distintos. El comando cambia la temporada global activa y su configuración de Materiales: ejecutarlo solo en el servidor de pruebas, fuera de `APP_ENV=production`.

```bash
php artisan temporadas:probar-activaciones <UUID_A> <UUID_B> --confirmar-entorno-pruebas
```

El comando inicia dos procesos Artisan, espera a que ambos lleguen a la barrera, los libera juntos y exige que ambos terminen bien y que quede exactamente una temporada activa. Presenta los errores de cada proceso para investigar bloqueos o reintentos.

Para comprobar la inserción dentro de `guardar()` frente a `activar()`, activar primero la temporada A y dejar preparada la temporada B para activarla. El segundo escenario crea automáticamente una **temporada productiva persistente**, con código y prefijo nuevos y fechas posteriores a las existentes:

```bash
php artisan temporadas:probar-activaciones <UUID_A> <UUID_B> --escenario=guardar-activar --confirmar-entorno-pruebas
```

Ejecutar este segundo escenario una sola vez en el servidor de pruebas y conservar el código `CONC-...` que muestra el comando para identificar su registro. Ambos escenarios modifican la temporada vigente. Al terminar, volver a activar A desde Accesos; la temporada creada permanecerá inactiva en el historial.
