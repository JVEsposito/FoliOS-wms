# Ingresos Portería

Bitácora de llegadas de camiones, independiente de la temporada y de los inventarios.
Se abre en `/oficina/porteria` desde el navegador del celular/PDA en la red de planta.
La aplicación Android también ofrece el módulo nativo Portería en PDA, tablet y celular.
Conserva el tema y el acceso de FoliOS.

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
`npm run build`, reiniciar el worker si corresponde. Para la aplicación Android, publicar también el bundle móvil descrito más abajo.
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

## Portería dentro de la APK

El módulo móvil `porteria` utiliza las mismas llegadas, API y avisos de Romana.
Disponible en las variantes PDA y tablet; la APK de tablet también se puede instalar
en un celular Android. El guardia cuyo único módulo sea Portería entra directamente
después del login o del cambio obligatorio de contraseña.

La migración `2026_10_20_180000_habilitar_porteria_movil` agrega Portería únicamente a
los perfiles iniciales de Guardia y Administrador que ya tengan Ingresos Portería
en Oficina. Conserva su estado activo/inactivo y sus demás módulos. Los perfiles
personalizados requieren marcar **Portería** en los módulos PDA/tablet y conservar
**Ingresos Portería** en Oficina. El equipo debe estar autorizado en Accesos.

El guardia registra cliente u Otro, transporte, conductor, motivo, guías, cantidad y
observación. Consulta la bitácora paginada por fecha de Chile y búsqueda, con detalle
e historial. Las correcciones y anulaciones permanecen reservadas a Administración;
revisar una llegada corresponde a Romana. El token móvil lleva `tablet:porteria`;
un token de otro módulo no autoriza estas operaciones.

Antes de enviar se persiste el UUID y la captura en AsyncStorage, separados por
servidor, usuario y equipo. Ante respuesta perdida, timeout o cierre de la app se
bloquea la edición y se reenvía el mismo intento, sin crear otra llegada. Un error al
guardar localmente impide enviar. Solo se confirma la llegada tras respuesta del
servidor; no es una cola de registros sin conexión. Un reintento recibido se confirma
incluso si el cliente se desactivó después. Un error de validación en el primer envío
permite corregir; un envío previo sin resultado cierto se conserva para revisión.

### Despliegue móvil

1. Respaldar la base; actualizar backend; `php artisan migrate --force` y
   `php artisan optimize:clear`. Reiniciar el worker según el procedimiento habitual.
2. Publicar el bundle móvil en `production` (tablet/celular) y `pda`. Usar los canales
   de pruebas correspondientes antes de desplegar en producción.
3. No se agregan bibliotecas ni cambios nativos: puede publicarse por OTA para las
   APK instaladas con runtime compatible. Para equipos sin FoliOS, generar e instalar
   la APK de la variante elegida. Reiniciar la app e iniciar sesión con el guardia.
4. Autorizar cada equipo en Accesos. En celulares registrar también su código de
   dispositivo; no necesitan computador ni abrir el navegador.

### Prueba física

Con una cuenta Guardia y un equipo autorizado, comprobar login directo en PDA,
tablet y celular Android; registrar dos guías con ceros iniciales, Otro y un servicio
sin guía. Revisar la llegada y el aviso en Romana. Cortar la conexión después de enviar,
cerrar y abrir la app y reintentar: una llegada y un evento de registro. Comprobar
bitácora/detalle y que el guardia no puede corregir, anular ni entrar a Romana.
