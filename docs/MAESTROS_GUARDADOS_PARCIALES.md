# Guardados parciales de Maestros (después del #501)

Los guardados habituales de categoría, especie, variedad, calibre, envase, marca y CSG dejan de reconstruir toda la temporada. La proyección toca únicamente artículos u orígenes dependientes del maestro modificado. Las combinaciones se obtienen mediante la relación de variedades autorizadas por CSG y la propiedad del envase, en lotes de 500 filas. Se mantienen IDs y códigos externos existentes y no se borran referencias históricas.

El escritor bloquea primero la temporada. El maestro, su proyección y el incremento de versión se confirman en una sola transacción. Un error revierte los tres. La lectura del catálogo de la PDA y la jerarquía administrativa toman el bloqueo compartido de esa misma temporada para impedir que un ETag o versión describa tablas de momentos diferentes. Las operaciones sin cambios no incrementan la versión.

Las respuestas de escritura conservan `data` y agregan `publicacion`: temporada, versión anterior, versión resultante y contadores. Oficina aplica el elemento recibido sin volver a pedir el resumen ni la jerarquía. Si su versión anterior no coincide, recupera la vista con el manejo de errores del #501, sin repetir la escritura. Se conservan los valores en los otros formularios.

La reconstrucción completa queda disponible para importaciones, reparación y migración de temporada. No se ejecuta desde los guardados habituales. Este cambio no convierte esos procesos masivos en publicaciones por etapas; deben ejecutarse fuera del horario operativo mientras no tengan su propio circuito de preparación. La transacción existente conserva la publicación completa.

## Despliegue y comprobación

Ejecutar migraciones (índices por temporada/activo para los contadores) y `npm ci && npm run build`, seguido de `php artisan optimize:clear`. No cambia el contrato operativo ni requiere APK nueva.

La aceptación de 1–2 segundos se debe medir en el catálogo real. Las pruebas verifican equivalencia con la reconstrucción completa, aislamiento de otra especie, cambios de padre, baja/reactivación, IDs/códigos conservados, rollback y aplicación parcial en Oficina. No se afirma un tiempo en el servidor de planta sin medirlo.
