# Guardados parciales de Maestros (después del #501)

Los guardados habituales de categoría, especie, variedad, calibre, envase, marca y CSG dejan de reconstruir toda la temporada. La proyección toca únicamente artículos u orígenes dependientes del maestro modificado. Las combinaciones se obtienen mediante la relación de variedades autorizadas por CSG y la propiedad del envase. Se recorren los artículos u orígenes afectados en grupos de 100 y se guardan en lotes de 500 filas. El cruce no se pagina con OFFSET: cada página lo volvía a recorrer y ordenar completo, y con 1,5 millones de combinaciones un calibre nuevo repetía la consulta cientos de veces. Se mantienen IDs y códigos externos existentes y no se borran referencias históricas.

## El catálogo no bloquea la temporada

Casi todas las tablas operativas (recepciones de Romana, lotes MP, Validación MP, movimientos de envases y otras) tienen una FK a `temporadas`, y cada inserción en ellas toma un bloqueo compartido de la fila de la temporada. Hasta el 24-11-2026, el catálogo bloqueaba esa fila durante todo el cálculo de combinaciones, y Romana y lotes esperaban con él. Con 1,5 millones de combinaciones, eso eran minutos, por encima de los 50 segundos que espera MySQL.

- Las escrituras del catálogo se ordenan entre sí con `BloqueoCatalogoValidacion`, que bloquea una fila propia por temporada en `bloqueos_catalogo_validacion`.
- La versión sube como último paso (`publicar()`). Desde ahí la fila de la temporada queda bloqueada solo hasta el commit. Los contadores se calculan antes.
- Artículos, orígenes y combinaciones no tienen FK a `temporadas`. Son derivados del catálogo, se escriben por cientos de miles de filas y escribirlos no debe bloquear la temporada. Su índice por temporada se conserva.
- La sincronización con SAG toma primero el bloqueo del catálogo y después la temporada en modo compartido, en el mismo orden que las demás escrituras.

El maestro, su proyección y el incremento de versión siguen confirmándose en una sola transacción, y un error revierte los tres. La jerarquía administrativa y la transferencia de la PDA leen la temporada con bloqueo compartido: la publicación de una versión espera a que terminen, así que un ETag o una versión nunca describen tablas de momentos diferentes. La descarga paginada lee un snapshot y compara la versión al empezar y al terminar cada página. Las operaciones sin cambios no incrementan la versión.

La migración y la copia de temporada siguen bloqueando las temporadas de forma explícita, porque cambian de temporada.

Las respuestas de escritura conservan `data` y agregan `publicacion`: temporada, versión anterior, versión resultante y contadores. Oficina aplica el elemento recibido sin volver a pedir el resumen ni la jerarquía. Si su versión anterior no coincide, recupera la vista con el manejo de errores del #501, sin repetir la escritura. Se conservan los valores en los otros formularios.

La reconstrucción completa queda disponible para importaciones, reparación y migración de temporada. No se ejecuta desde los guardados habituales. Ya no frena a Romana ni a los lotes, pero sigue siendo larga y obliga a las PDA a descargar el catálogo de nuevo: conviene ejecutarla fuera del horario operativo mientras no tenga su propio circuito de preparación. La transacción existente conserva la publicación completa.

## Despliegue y comprobación

Ejecutar migraciones (índices por temporada/activo para los contadores; `bloqueos_catalogo_validacion` y retiro de las FK de la proyección a `temporadas`, que en MySQL solo cambia metadatos y no copia las tablas) y `npm ci && npm run build`, seguido de `php artisan optimize:clear`. No cambia el contrato operativo ni requiere APK nueva.

La aceptación de 1–2 segundos se debe medir en el catálogo real. Las pruebas verifican equivalencia con la reconstrucción completa, aislamiento de otra especie, cambios de padre, baja/reactivación, IDs/códigos conservados, rollback y aplicación parcial en Oficina. No se afirma un tiempo en el servidor de planta sin medirlo.
