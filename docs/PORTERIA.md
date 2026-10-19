# Ingresos Portería

Bitácora de llegadas de camiones, independiente de la temporada y de los inventarios.
Se abre en `/oficina/porteria` desde el navegador del celular/PDA en la red de planta.
No requiere una APK nueva. Conserva el tema y el acceso de FoliOS.

## Registro

Un cliente del catálogo global por llegada, o «Otro» con nombre libre. Otro no crea
maestros. Se registran patente, tipo de camión, conductor, motivo (carga, vacío o servicio),
fecha y hora del servidor, y varias guías del mismo cliente. Las guías conservan ceros
iniciales. Vacíos y servicios admiten una lista de guías vacía. Cantidad y unidad
(bins/bultos), patente del carro y observación son opcionales.

Cada llegada tiene un correlativo PORT independiente de las guías. Una guía puede
llegar repartida en varios camiones y la patente puede volver durante el día.
El UUID del intento evita duplicados por doble toque o reintento; cambiar los datos de
un intento ya recibido devuelve 409. Un intento sin respuesta se conserva por usuario
en la pestaña del navegador y se reenvía igual. Requiere conexión para confirmar el
registro; no anuncia un ingreso a Romana mientras solo esté en el equipo.

## Accesos e historial

La migración crea el perfil Guardia de portería y agrega el módulo Ingresos Portería
al perfil inicial de Administrador. Los perfiles personalizados se asignan desde Accesos.
El guardia registra y consulta. Los usuarios con consulta de Romana pueden consultar
la bitácora. Solo un administrador con el módulo corrige o anula, con motivo e historial.
Las correcciones verifican la versión; nunca se elimina físicamente un ingreso.
El alta no crea recepciones, movimientos ni hechos financieros.

## Instalación

Respaldar la base operativa y desplegar normalmente: `php artisan migrate --force`,
`npm run build`, reiniciar el worker si corresponde. No hay cambios en mobile/.
La bitácora diaria usa fecha de Chile, incluidos los cortes de medianoche.

## Romana, avisos y PDF diario

Romana muestra una tarjeta con los ingresos pendientes y las últimas llegadas. La consulta
ligera corre cada 10 segundos con la ventana visible y conectada; muestra cuándo se actualizó
y conserva la bandeja si se pierde la conexión. El aviso queda disponible aunque cambie el
turno o llegue la medianoche: los pendientes se consultan sin limitarse al día actual.

Desde «Ver ingresos y PDF diario» se revisa la bitácora. Marcar revisado guarda responsable
y hora; no genera una recepción ni modifica pesos. Un perfil de consulta puede leer y
exportar; no marcar revisado. Portería registra y consulta, sin permisos de Romana.
Una corrección administrativa devuelve el ingreso a pendientes para que Romana la revise.

El PDF usa la fecha de Chile seleccionada, incluye cada llegada y sus guías, responsable,
observación y estado. Incluye los anulados señalados y los excluye de los totales; suma
bins y bultos por separado. Repite cabeceras y divide registros largos entre páginas.
Ambos PR apuntan a main: primero registro, luego esta integración. Desplegar ambos juntos.
