# Finanzas

Finanzas valoriza lo que registra la operación **sin modificarla**. La regla es una sola:
la operación no conoce a Finanzas y las áreas operativas no manejan costos; Finanzas
«bebe» de lo que ya funciona.

Este documento cubre el primer paso: la base propia de Finanzas y la **captura de hechos
valorizables**. El tarifario y la prefactura en paralelo se construyen sobre estos hechos.

## Cómo se separa de la operación

| Pieza | Dónde | Regla |
| --- | --- | --- |
| Base de datos | Conexión `finanzas` (por omisión, `<DB_DATABASE>_finanzas` en el mismo servidor) | Solo Finanzas escribe aquí. Tablas con prefijo `fin_`. |
| Lectura de la operación | Conexión `operacion_lectura` | Sesión `READ ONLY`: la base rechaza cualquier escritura. |
| Código | `app/Finanzas` | Nada fuera de `app/Finanzas` lo referencia. |
| Rutas | `routes/finanzas.php` (`/api/finanzas/...`) | Leen la operación y escriben solo en la base de Finanzas. |
| Oficina | Administración → Finanzas → *Hechos valorizables* | Módulo de acceso `finanzas.prefactura`. |

Las tablas de Finanzas no tienen llaves foráneas hacia la operación: guardan el
identificador del registro operativo y una copia de lo leído (cliente, temporada,
referencia, medidas), de modo que un cambio posterior en la operación no altera lo ya
capturado sin dejar rastro.

`tests/Feature/Architecture/FinanzasArchitectureTest.php` vigila estas reglas: la
operación no importa nada de Finanzas, los modelos de Finanzas viven en su base, Finanzas
consulta la operación solo por `LectorOperacion`, las fuentes no escriben y ninguna
migración cruza llaves entre ambas bases. `FinanzasSoloLeeLaOperacionTest` comprueba que
una captura solo ejecuta `SELECT` sobre la base operativa y que la conexión de lectura
rechaza escrituras.

## Hechos valorizables

Un hecho es algo que la operación registró y que la planta puede cobrar. Cada hecho tiene
cliente, fecha operacional (hora de planta), una referencia legible y sus medidas.

| Fuente | Cuándo es un hecho | Fecha | Medidas | Cliente |
| --- | --- | --- | --- | --- |
| Recepciones en romana | Recepción cerrada | Ingreso del camión | kilos netos, envases declarados | Cliente de la recepción |
| Pallets validados | Validación PT aceptada y aprobada, con folio | Hora de la PDA | bultos, pallets, saldos, cajas | Cliente de Validación vinculado al cliente global |
| Recepción de fruta embalada | Recepción aceptada | Llegada | recepciones, bultos, pallets, saldos, cajas | Cliente de la recepción |
| Prefrío | Folio aprobado en un proceso | Fin del proceso | bultos, pallets, saldos, cajas | Cliente del folio |
| Estadía en cámara | Folio en custodia en cámaras de producto | Ingreso → salida | bultos, pallets, saldos, cajas | Cliente del folio |
| Repaletizajes | Repaletizaje confirmado | Confirmación | operaciones, folios de origen y resultantes, cajas | Cliente del folio resultante |
| Pallets despachados | Folio despachado en una carga | Cierre del despacho | bultos, pallets, saldos, cajas | Cliente del folio |

El cliente de un folio sale de su composición por CSG (`trazabilidad_folio_origenes`); si
no está vinculada, del nombre de la exportadora, comparado con los clientes globales y con
los clientes de Validación sin distinguir mayúsculas, tildes ni espacios. Un hecho sin
cliente queda marcado y se vuelve a leer en cada captura: al vincular el cliente en los
maestros, el hecho se completa solo.

### Versiones

Un hecho nunca se edita. Si la operación lo corrige, la captura crea una versión nueva y la
anterior deja de ser vigente; si la operación lo anula o deja de cumplir la condición (una
recepción reabierta, un prefrío que pasa a reproceso), la nueva versión queda **anulada**.
El historial completo se ve en la oficina con el botón de versión de cada fila.

| Motivo | Significado |
| --- | --- |
| Captura | Primera vez que Finanzas ve el registro. |
| Cambió en la operación | Corrección, cierre de una estadía, cambio de cliente. |
| Anulado en la operación | La operación lo anuló o dejó de ser un hecho. |
| Ya no existe en la operación | El registro se borró (por ejemplo, un reinicio de datos de prueba). |

Un registro anulado antes de la primera captura no deja hechos.

### Estadía en cámara

La estadía mide la **custodia** de un pallet o saldo, no cada movimiento:

- Empieza cuando el folio entra a una cámara de contenido *productos* (cualquier tipo:
  almacenaje, tránsito, preparación o despacho).
- Sigue abierta mientras el folio esté vigente, aunque salga un rato de la cámara (un
  reordenamiento, el andén). Cada salida y regreso suma una interrupción; si el folio está
  fuera de cámara al leer, la fila lo indica.
- Termina con el despacho (hora de cierre de la carga), el consumo en un repaletizaje o la
  anulación de su validación.
- Si el folio quedó en un estado terminal sin un evento con hora (por ejemplo, regularizado
  al cerrar la temporada), la salida es la última salida de cámara registrada y se marca
  como **estimada**.
- Un folio nacido en un repaletizaje empieza su estadía en el repaletizaje si heredó la
  ubicación de un origen en cámara; si no, cuando lo ubican. Si ese repaletizaje se anula,
  su estadía queda anulada y la de los orígenes vuelve a quedar abierta.

Las horas son las del dispositivo (una PDA puede sincronizar después), en el orden en que el
servidor aplicó los movimientos. Los días cobrables los calcula la prefactura con las
reglas del tarifario (días libres, mínimo, corte), no la captura.

## Captura

- Corre cada 10 minutos (`finanzas:capturar-hechos --programada` en `routes/console.php`)
  y también a pedido con **Leer ahora** (requiere rol Administrador con el módulo).
- Cada fuente lee lo que cambió desde su última marca, repasando 15 minutos hacia atrás.
  Releer un registro sin cambios no crea versiones.
- Cada fuente tiene una conciliación **completa** cada 24 horas: recorre toda la operación, recoge lo que una
  lectura incremental no alcanzó a ver y anula como *Ya no existe en la operación* lo que
  ya no está.
- Si una fuente falla, las demás se capturan igual y la lectura queda *con errores*; la
  fuente que falló se vuelve a leer en la próxima. Su conciliación solo se marca exitosa
  después de terminar la lectura y anular los hechos desaparecidos. Una captura parcial
  no pospone las conciliaciones de las fuentes que no leyó.
- Nunca corren dos capturas a la vez.

Comando manual:

```bash
php artisan finanzas:capturar-hechos                 # todas las fuentes
php artisan finanzas:capturar-hechos --fuente=estadia
php artisan finanzas:capturar-hechos --conciliar     # lectura completa
```

Variables de entorno (todas opcionales):

| Variable | Por omisión | Uso |
| --- | --- | --- |
| `FINANZAS_DB_DATABASE` | `<DB_DATABASE>_finanzas` | Base de Finanzas. |
| `FINANZAS_DB_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD` | Los de `DB_*` | Servidor y usuario de la base de Finanzas. |
| `FINANZAS_LECTURA_DB_USERNAME`, `_PASSWORD` | Los de `DB_*` | Usuario con que Finanzas lee la operación. |
| `FINANZAS_CAPTURA_HABILITADA` | `true` | Pausa la captura programada (la manual sigue disponible). |
| `FINANZAS_CAPTURA_MARGEN_MINUTOS` | `15` | Repaso hacia atrás de cada lectura. |

## Instalación en el servidor

1. `php artisan migrate --force` crea la base de Finanzas si el usuario de la conexión
   tiene permiso `CREATE`. Si no lo tiene, cree la base antes, con el mismo juego de
   caracteres de la operativa:

   ```sql
   CREATE DATABASE estiba_wms_finanzas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Recomendado: un usuario propio de solo lectura para que Finanzas consulte la operación
   (la sesión ya es de solo lectura, pero así la protección también está en los permisos).
   Nombres de ejemplo:

   ```sql
   CREATE USER 'finanzas_lectura'@'localhost' IDENTIFIED BY '<clave>';
   GRANT SELECT ON estiba_wms.* TO 'finanzas_lectura'@'localhost';
   ```

   y en `.env`: `FINANZAS_LECTURA_DB_USERNAME` y `FINANZAS_LECTURA_DB_PASSWORD`.

3. Los respaldos deben incluir **ambas bases**. El registro de migraciones vive en la base
   operativa: restaure las dos del mismo momento.

4. El perfil inicial de Administrador recibe el módulo *Hechos y prefactura*. Para otros
   usuarios, asígnelo desde Accesos (rol base Administrador para capturar a pedido; un perfil
   de solo consulta puede ver los hechos).

`php artisan migrate:fresh` confirmado sobre la conexión operativa reinicia también las
tablas de Finanzas, cuando su registro de migraciones ya está vacío. Cancelar el comando
no borra ninguna tabla financiera. Un `db:wipe` suelto conserva Finanzas; el siguiente
`migrate` se detiene si quedaron datos financieros sin su registro de migraciones.

La migración correctiva recrea la estructura si el proveedor anterior ya borró todas las
tablas financieras y sus migraciones siguen registradas. No recupera los datos perdidos:
para ellos se necesita el respaldo. Si solo falta parte del esquema, se detiene y conserva
las tablas restantes para su recuperación.

`FINANZAS_DB_URL` determina la base efectiva, también al crearla. Si la operación usa
`DB_URL`, Finanzas hereda el servidor y las credenciales de esa URL; las credenciales
`FINANZAS_LECTURA_DB_*` se aplican después y prevalecen para la conexión de solo lectura.

El reinicio controlado de datos
de prueba (Accesos y temporadas) no toca Finanzas: en la siguiente lectura completa, los
hechos de los registros borrados quedan como *Ya no existe en la operación*.

## Próximos pasos

1. Tarifario: servicios facturables por fuente y medida, precios por cliente y temporada.
2. Prefactura en paralelo: valorización mensual por cliente para compararla con la
   facturación actual, sin reemplazarla todavía.
3. Cierre de prefactura y ajustes: lo que cambie después de cerrar un mes se refleja como
   ajuste del mes siguiente, nunca editando lo cerrado.
