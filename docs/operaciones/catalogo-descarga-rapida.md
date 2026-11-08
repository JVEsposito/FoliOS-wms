# Descarga rápida de la maestra en PDA y tablet

La paginación de 1.000 filas evitó construir toda la maestra en memoria, pero
con 76.420 artículos, 342 orígenes y 523.632 combinaciones requiere 603 páginas
sucesivas, además del índice. Cada página añade latencia de red, verificaciones
y escrituras en el equipo. Aumentar la memoria PHP no reduce ese recorrido.

El PR #600 ya corrigió el plan SQL de combinaciones para leer en orden de cursor
sin ordenar el cruce completo en cada página. Esta mejora conserva su hint
`JOIN_PREFIX(c) INDEX(c comb_val_temporada_activo)` y su prueba EXPLAIN. La descarga
compacta y concurrente complementa esa corrección.

## Transporte negociado

La app solicita `GET /api/validacion/catalogos?formato=paginas-v1&transporte=compacto-v1`.
El backend anuncia los tamaños y entre uno y cuatro intervalos de combinaciones.
Un servidor anterior ignora el parámetro y devuelve el contrato anterior, que
la app sigue aceptando. Los clientes anteriores conservan las páginas de 1.000.

| Colección | Filas máximas por página rápida |
| --- | ---: |
| Categorías | 1.000 |
| Artículos | 5.000 |
| Orígenes | 1.000 |
| Combinaciones | 10.000 |

Cada página usa un diccionario de textos. Las combinaciones transmiten únicamente
los pares exactos artículo/origen usados por el índice local CSR; no repiten el ID
de combinación ni códigos que la app no utiliza. No se crean combinaciones nuevas
ni se modifica el contenido enviado al registrar una validación.

El servidor obtiene los extremos del índice y divide el espacio de UUID en hasta
cuatro intervalos contiguos, disjuntos y completos, sin COUNT ni OFFSET. Los UUID
pueden distribuirse de manera desigual: no se garantiza que los cuatro recorridos
terminen juntos. El cliente solicita hasta tres colecciones de maestros a la vez
y después hasta cuatro páginas de combinaciones a la vez. El número de solicitudes
simultáneas queda acotado independientemente del crecimiento de la temporada.

Las páginas pueden comprimirse con gzip nivel 4 si el cliente lo acepta y el
resultado ocupa menos. `Vary: Accept-Encoding` distingue las representaciones;
`X-Catalog-Target` verifica el JSON descomprimido y el ETag identifica los bytes
enviados. `X-Catalog-Bytes` representa el JSON antes de compresión. También pueden
beneficiarse de gzip las apps anteriores que usen el endpoint paginado.

## Integridad y recuperación

- Se comprueban temporada, versión, colección, cursor, intervalo, cantidades,
  diccionario y referencias antes de instalar el catálogo completo.
- El equipo conserva cada página con SHA-256 y serializa las escrituras del avance
  para evitar que dos solicitudes se sobrescriban. Verifica al guardar y al releer,
  sin repetir una tercera verificación de los mismos bytes. Crea cada directorio
  una vez por ejecución y limita las actualizaciones ordinarias de progreso a diez
  por segundo.
- Una interrupción permite reutilizar las páginas completas de esa versión y del
  mismo perfil de descarga. El avance rápido tiene un archivo separado del modo
  anterior: una descarga parcial antigua no se mezcla con la nueva.
- Ante un error, se espera a todas las solicitudes en curso antes de devolverlo.
  La copia instalada, la bandeja de capturas y la jornada permanecen disponibles.
- Un cambio de versión devuelve 409 y obliga a obtener un conjunto coherente. La
  limpieza de partes antiguas ocurre únicamente después de completar la descarga.

Una versión nueva sigue necesitando una descarga completa. Este modo no implementa
deltas entre versiones. Un catálogo instalado sin cambios continúa usando 304.

## Medición reproducible

La prueba de transporte usa 76.420 artículos, 342 orígenes, una categoría y
523.632 pares válidos. Ejecuta el descargador real con HTTP y archivos simulados,
compara todos los pares y calcula gzip nivel 4 sobre los cuerpos JSON.

| Medida de la simulación | Páginas anteriores | Transporte rápido |
| --- | ---: | ---: |
| Páginas de datos | 603 | 74 |
| Peticiones simultáneas máximas | 1 | 4 |
| Bytes JSON | 120.859.976 | 14.225.056 |
| Bytes gzip estimados | 18.900.458 | 5.355.964 |

Son aproximadamente 87,7% menos páginas y 71,7% menos bytes gzip en esta muestra.
No son tiempos medidos en la PDA, Hermes o Wi-Fi de planta. El número de páginas
depende de las cantidades y distribución reales de IDs.

Desde `mobile/`:

```bash
node --experimental-strip-types --test --test-name-pattern='volumen v443' tests/validationCatalogTransfer.test.mjs
```

Para medir el backend sin modificar datos, desde la raíz del repositorio:

```bash
php artisan validacion:medir-catalogo --paginas=3 --json
php artisan validacion:medir-catalogo --paginas=3 --rapido --json
```

`--paginas=0` recorre todas las páginas. El comando informa transporte, tiempos,
memoria y máximo gzip estimado por colección. Su recorrido es secuencial y mide
trabajo de diagnóstico en PHP: no mide el tráfico real ni la concurrencia móvil.

## Despliegue y comprobación en planta

1. Desplegar primero el backend. Mantener la corrección SQL del #600.
2. Distribuir la app actualizada tanto en PDA como en tablet; ambas necesitan el
   nuevo cliente para negociar las páginas compactas y los cuatro recorridos.
3. Comprobar una descarga completa y otra interrumpida/reanudada, con una copia
   anterior y capturas pendientes. Registrar versión, páginas, bytes de red,
   duración y memoria máxima del servidor con varios equipos sincronizando.
4. Repetir sin cambios y confirmar 304. Comprobar una edición durante la descarga
   y que se conserve la copia anterior hasta instalar la versión nueva completa.

No requiere migraciones ni dependencias nativas nuevas. No borrar datos de los
equipos ni reinstalar para forzar la actualización. Mantener el límite PHP de
1.024 MB mientras se mide el consumo real; esta optimización no justifica bajarlo
sin esa medición. La paginación acota la memoria del servidor por petición y el
índice numérico evita crear medio millón de objetos de combinación en la PDA.
