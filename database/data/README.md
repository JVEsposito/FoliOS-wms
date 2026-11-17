# Catálogo de países para Inspección SAG

`paises_iso_3166_1.json` se generó desde `i18n-iso-countries` 7.14.0 (MIT),
utilizando su tabla de códigos y traducciones al español. Incluye el catálogo
ISO 3166-1 y la entrada operacional XK/Kosovo que la fuente mantiene para
evitar excluir un destino utilizado internacionalmente.

La aplicación copia estos datos a su catálogo global durante la migración. Los
lotes SAG conservan snapshots de los nombres y de la composición de bloques,
por lo que una actualización futura no altera inspecciones históricas.

## Puntos de transporte para embarques

`puertos_embarque.json` carga el catálogo inicial de puertos, aeropuertos y
pasos utilizados por la planificación de embarques. Cada registro se vincula
al catálogo global de países mediante su código ISO alpha-2. La operación usa
las claves foráneas para evitar combinaciones inválidas y conserva el nombre
como snapshot en cada embarque o instructivo para no alterar documentos
históricos si el catálogo cambia posteriormente.

`puertos_embarque_ampliacion.json` (noviembre de 2026) agrega 769 puntos: la
migración `ampliar_puntos_transporte_embarques` inserta solo los códigos que
faltan y no toca los existentes.

- **Puertos (406):** código UN/LOCODE verificado contra la lista oficial
  (`datasets/un-locode`), con función de puerto. Son los puertos de contenedores
  y refrigerados de los mercados de la fruta, más los chilenos y los de la región.
- **Aeropuertos (298):** código `AIR-` + IATA, verificado contra
  `datasets/airport-codes`; aeropuertos con carga internacional.
- **Pasos terrestres (65):** código `PAS-`. Los pasos habilitados de Chile con
  Perú, Bolivia y Argentina son puntos de salida; su contraparte en el país
  vecino (`PAS-AR-…`, `PAS-BO-…`, `PAS-PE-…`) sirve como destino del
  instructivo. Incluye los principales cruces del Mercosur para camiones que
  siguen desde Argentina a Brasil, Paraguay y Uruguay.

En el calendario de embarques, la modalidad filtra el tipo de punto (marítimo →
puertos, aéreo → aeropuertos, terrestre → pasos; «Por confirmar» muestra todos)
y el país de destino ofrece todos los países activos, aunque no tengan puntos.
