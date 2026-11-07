# Escalabilidad del catálogo de validación

## Volumen y objetivo

El volumen informado en planta para v442 es de **76.420 artículos, 342 orígenes y
523.632 combinaciones**. El incidente anterior se resolvió aumentando el límite
de memoria PHP de 512 a 1.024 MB. Este cambio reduce el trabajo y las copias en
memoria; no modifica ese límite ni demuestra que pueda volver a bajarse.

Si falta el 40–50 % **del total final**, los maestros podrían terminar entre
1,67 y 2 veces su volumen actual. Las relaciones pueden crecer más que los
maestros cuando crecen a la vez productos y orígenes. Se ensayan hasta 2.094.528
relaciones como escenario de carga, **no como pronóstico de la temporada**.

## Qué cambia

### Servidor

- Se conserva la descarga paginada de hasta 1.000 filas por petición, con cursores,
  versión, hashes y reanudación. Cada petición evita materializar el catálogo completo.
- Durante la reconstrucción se retienen los campos necesarios del artículo, en
  lugar de decenas de miles de modelos Eloquent con atributos y originales.
- Los orígenes se agrupan por variedad permitida antes de cruzarlos con artículos.
  Se mantienen las restricciones de cliente, envase, CSG y vigencia.
- Las relaciones se guardan mediante `upsert` en lotes de hasta 500 dentro de la
  transacción existente. No hay un SELECT y un guardado por cada combinación.
  La clave única existente conserva ID, código externo y fecha de creación de
  cada vínculo. Solo se actualizan vigencia y fecha de modificación.
- La sincronización de un CSG usa los mismos lotes. Un error revierte la operación;
  no deja una proyección parcialmente publicada.

La creación/búsqueda de artículos sigue siendo por artículo. La reconstrucción
todavía tiene coste proporcional a los maestros y vínculos generados; no se
promete un tiempo constante ni se cambia la política de generar combinaciones.

### PDA y tablet

Cada página de combinaciones se convierte inmediatamente a posiciones numéricas
de artículo por origen. Se descartan sus objetos y UUID repetidos tras procesar
la página. Al terminar se conserva un índice compacto (`csr-v1`) con un entero
de 32 bits por vínculo y los límites de cada origen. Con v442 ocupa **2 MiB**
para las relaciones; con 2.094.528 vínculos, **8 MiB**. Los artículos, orígenes y
otros índices también consumen memoria y se cuentan por separado.

Las consultas usan búsqueda binaria y recorren las relaciones del origen sin
crear un `Set` adicional por cada CSG. La intersección entre varios CSG, las
variedades permitidas y los registros inactivos conservan su comportamiento.
El resultado representa **exactamente los pares habilitados por el servidor**;
no infiere que cualquier producto pueda usarse con cualquier origen.

El guardado nativo pasa a esquema 3. Conserva el respaldo por partes del cambio
anterior: maestros en bloques de 500 filas, posiciones numéricas en bloques de
8.192 y hashes por parte. El manifiesto se publica al terminar y se mantiene la
copia completa anterior. Se verifican rangos, orden y cantidades al cargarla.
Las copias existentes de esquemas 1 y 2 se leen y convierten; esquema 2 se procesa
por partes. No se borran bandejas ni jornadas. Las capturas siguen enviando los
IDs originales de artículo y origen; no cambia el contrato de envío.

Los contratos de catálogo completo y fragmentos siguen disponibles para clientes
anteriores. La reducción durante la descarga requiere la app nativa actualizada
y el endpoint paginado. La compatibilidad con un servidor anterior puede seguir
necesitando materializar su respuesta completa antes de compactar el respaldo.

## Medición reproducible

Desde `mobile`, con las dependencias instaladas:

```bash
node scripts/benchmark-validation-catalog.mjs
```

Resultado de referencia en **Node v24.22.1**, con datos sintéticos, páginas JSON
de 1.000 relaciones y procesos separados con heap limitado a 512 MiB:

| Representación y escenario | Artículos | Orígenes | Vínculos | Memoria retenida¹ MiB | Pico RSS² MiB | Mediana selección³ ms |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Anterior, cantidades v442 | 76.420 | 342 | 523.632 | 181,76 | 382,74 | 0,664 |
| Compacta, cantidades v442 | 76.420 | 342 | 523.632 | 60,94 | 234,64 | 0,717 |
| Compacta, escenario resta 40 % | 127.367 | 570 | 1.454.534 | 78,97 | 325,19 | 1,496 |
| Compacta, escenario resta 50 % | 152.840 | 684 | 2.094.528 | 98,85 | 358,89 | 1,941 |

¹ `heapUsed + arrayBuffers` tras GC: incluye catálogo, índice y el entorno del
benchmark. La representación anterior mantiene objetos de relaciones y conjuntos
de UUID; la compacta conserva posiciones numéricas. No es una medida de PHP ni
del dispositivo Android.

² Pico del proceso Node completo, incluido el compilador TypeScript utilizado
por el benchmark. No equivale al heap ni a la RAM libre necesaria de una PDA.

³ Intersección de dos orígenes, mediana de siete muestras. Los nombres y la
distribución de compatibilidades son sintéticos; tiempos y memoria varían según
datos, motor y equipo. No es una prueba de Hermes, interfaz ni Wi-Fi de planta.

Los dos escenarios mayores aumentan tanto artículos como orígenes; sus vínculos
se incrementan por el producto de esos factores como prueba de esfuerzo. No
derivan vínculos nuevos en el catálogo real.

### Consumo PHP real, sin reconstruir datos

En el backend desplegado:

```bash
php artisan validacion:medir-catalogo --paginas=3 --json
```

Lee hasta tres páginas de cada colección y muestra versión, conteos, límite PHP,
filas, máximo de bytes por página, pico de memoria PHP y duración máxima de página.
`completa: false` identifica una muestra, no una descarga incompleta del equipo.
Para recorrer todas las páginas, en un intervalo adecuado para lecturas:

```bash
php artisan validacion:medir-catalogo --paginas=0 --json
```

El comando no reconstruye maestros, publica caché ni modifica movimientos.
Es una medida del **proceso CLI**, incluyendo el diagnóstico y la decodificación
JSON; no mide FPM/HTTP, compresión, red ni memoria Android. Un cambio de versión
durante el recorrido cancela la medición para no mezclar versiones. Los conteos
son de filas activas de la proyección; una relación a un maestro inactivo se
excluye de las páginas aunque su propia bandera siga activa.

Para validar en planta, comparar en ambos tipos de equipo: descarga desde cero,
descarga interrumpida/reanudada, nueva versión y reinicio sin red. Registrar
duración total, memoria máxima del proceso Android, bytes comprimidos reales,
versión y tiempos HTTP, además del informe PHP. Repetir con el catálogo de fin
de temporada; no extrapolar los milisegundos de Node al equipo operativo.

## Alcance y despliegue

Integrar sobre los cambios de descarga paginada y respaldo por partes (#595 y
#596). Actualizar backend y luego distribuir la app **en PDA y tablet**,
conservando sus datos. No requiere migraciones ni dependencias nativas nuevas.
Mantener el límite PHP de 1.024 MB hasta disponer de mediciones reales suficientes.
Una app antigua no obtiene la compactación por actualizar solamente el servidor.

La primera descarga y una versión cambiada por páginas siguen teniendo coste
proporcional al catálogo completo; este cambio no agrega deltas al protocolo
paginado. La sincronización sin cambios conserva el 304. El escenario de dos
millones de vínculos valida la representación en memoria, no garantiza tiempos
de transferencia en la red de planta ni crecimiento ilimitado. Si las mediciones
de red dejan de cumplir la operación, el siguiente paso será transferir cambios
por versión manteniendo las mismas verificaciones de integridad.

## Verificación automatizada

- Pruebas móviles de índices: equivalencia de pares, intersección de CSG,
  restricciones de variedades, inactivos y rechazo de posiciones inválidas.
- Guardado/carga de 76.420 artículos, 342 orígenes y 523.632 relaciones, con
  verificación de todos los pares; escritura por partes, migración de esquemas
  anteriores, interrupción, corrupción y recuperación del respaldo.
- Pruebas de descarga paginada con interrupción, reanudación, hashes y versiones.
- Pruebas MySQL: 1.030 vínculos en tres escrituras, estabilidad de IDs/códigos,
  restricciones y reversión completa al fallar el segundo lote; diagnóstico
  sin modificar la proyección ni su versión.

Los comandos de validación del cambio son `npm run typecheck`,
`npm run test:catalog`, `npm run export:pda`, `npm run export:android` y las suites
PHP de catálogo jerárquico, importación, operación, sincronización SAG,
proyección por lotes y API de catálogo.
