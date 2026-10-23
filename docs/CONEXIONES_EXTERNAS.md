# Conexiones externas

Administración → Accesos → Conexiones externas permite registrar perfiles de acceso
MySQL desde el servidor de FoliOS. Los campos son nombre, servidor/IP, puerto,
base de datos, usuario y contraseña. Se entrega el formulario vacío: no hay
direcciones, cuentas ni claves de planta en código, fixtures, variables de ejemplo
o documentación.

El acceso que se entrega mediante ODBC puede corresponder a distintos motores.
Esta primera implementación se conecta a **MySQL mediante pdo_mysql**, sin exigir
un DSN ODBC instalado en el sistema operativo. No incluye otros motores ni un
editor de consultas SQL.

## Uso

1. Ejecutar las migraciones y compilar Oficina durante el despliegue habitual.
2. Entrar con un administrador habilitado para Administración de accesos.
3. Completar los campos con los datos entregados por el administrador del origen
   y guardar. El puerto se completa manualmente, igual que los demás datos.
4. Pulsar «Probar conexión». Se prueba la configuración **guardada**, desde el
   servidor FoliOS; el PC del usuario no se conecta directamente al origen.
5. Si falla, el mensaje indica la causa sin mostrar servidor, usuario ni clave:
   falta pdo_mysql, el servidor no llega a la base (IP, puerto o firewall), usuario
   o clave rechazados, sin permiso sobre la base, o base inexistente. La respuesta
   no incluye el error técnico del controlador.

Dejar la contraseña vacía al editar conserva la clave guardada. Escribir una
nueva la reemplaza. Puede desactivarse el perfil sin eliminarlo. Una edición
invalida la prueba anterior y requiere conocer la versión vigente del perfil.

## Almacenamiento y acceso

- Solo `administrar-accesos` puede consultar, crear, editar o probar perfiles.
- Servidor, puerto, base, usuario y contraseña se guardan juntos con el cast
  `encrypted:array` de Laravel. Se necesita conservar la APP_KEY de la
  instalación para poder usarlos; nunca se cambia esa clave como parte de esta
  configuración.
- La contraseña no se devuelve en JSON ni en la serialización del modelo.
  El administrador puede recuperar los otros campos para editarlos.
- La interfaz no guarda estos datos en localStorage/sessionStorage; elimina
  sus datos al perder o cambiar la sesión y descarta respuestas tardías.
- Las respuestas administrativas llevan `Cache-Control: private, no-store`.
- Telescope excluye las solicitudes de esta sección y sus entradas asociadas,
  también en ambiente local. Los errores PDO se capturan sin reportarlos.
- La auditoría registra usuario, acción y nombres de campos modificados; no
  conserva valores de conexión ni contraseñas. Los perfiles son configuración
  global y no se eliminan con el reinicio operacional de una temporada.

## Prueba e importaciones posteriores

La prueba abre una conexión PDO independiente, con límite de conexión de cinco
segundos, sin conexiones persistentes ni sentencias múltiples. Configura la
sesión en lectura, abre una transacción, ejecuta únicamente `SELECT 1` y la
revierte. No modifica la conexión de la base operacional de FoliOS.

La cuenta del origen debe tener los permisos de lectura acordados en ese
servidor. La prueba comprueba conectividad y acceso a la base, no los permisos
sobre todas sus tablas.

Este paso no importa datos ni decide correspondencias entre sistemas. Para
preparar la importación hacen falta las tablas o vistas autorizadas, sus campos,
claves y la frecuencia de actualización. Los secretos se completan solamente
en la instalación de destino; no se incorporan a commits ni descripciones de PR.

## Explorar el árbol de la base

«Explorar origen» lista todas las tablas y vistas que el usuario puede descubrir dentro de
la base configurada. No se limita a una vista de stock ni enumera otras bases del servidor.
Al seleccionar una fuente se consultan sus columnas y tipos desde `information_schema`;
no se necesita consultar la definición SQL de las vistas con `SHOW CREATE VIEW`.

Se pueden seleccionar columnas y probar una muestra sin filtros, o agregar hasta ocho
filtros de igualdad. Cada muestra contiene hasta veinte filas por API (diez en Oficina),
hasta cuarenta columnas y hasta quinientos caracteres por celda. Los binarios se omiten.
Una muestra vacía se distingue de un error de acceso o de columnas; no modifica el stock.

El explorador requiere Administración de accesos. Las consultas se construyen en el
servidor a partir de las columnas descubiertas y parámetros, sin recibir SQL libre.
La muestra no se incorpora al stock ni se guarda en el navegador. Para entregarla desde
la cola se conserva un resultado temporal cifrado, accesible durante cinco minutos; el
scheduler elimina su contenido vencido. Al perder o cambiar la sesión se limpia la
pantalla y se descartan respuestas pendientes. La auditoría conserva quién consultó y la
acción, sin filas de origen, valores de filtros ni credenciales.

## Guardar la lectura de stock

Después de elegir una fuente, «Configurar para pendientes por validar» permite asociar
sus columnas al folio, cajas, cliente, especie, variedad, CSG, proceso y demás campos.
Solo folio y cajas son obligatorios. «Probar mapeo» devuelve hasta diez filas traducidas
sin importar nada. «Guardar mapeo» conserva esa selección y los filtros visibles,
cifrados en la instalación. Sin filtros se consulta toda la fuente: no se agregan filtros
de bodega, tipo de artículo o temporada por detrás.

La selección se usa tanto al pulsar «Leer ahora» como en las lecturas programadas.
Cada cambio de configuración o conexión separa su copia de las anteriores: hasta leer
correctamente la nueva selección no se presenta la copia antigua como stock vigente.
Un error, una cantidad inválida, más de 50.000 líneas o una respuesta vacía no retiran
pallets de la última copia correcta. Los cambios mientras responde el origen descartan
esa lectura. Las fechas del origen se interpretan en la zona horaria de la planta.

El árbol sirve para explorar cualquier tabla o vista accesible; este mapeo alimenta
la copia de stock por pallet, no importa procesos de otras áreas ni crea folios.
Sin un mapeo guardado se mantiene la configuración anterior por variables de entorno.
Deshabilitar un mapeo guardado apaga la lectura y no reactiva esa configuración anterior.

El primer uso de la conexión es **Frigorífico → Pendientes por validar**
(`/oficina/validacion/pendientes`). Se documenta en
[PENDIENTES_VALIDACION_ORIGEN.md](PENDIENTES_VALIDACION_ORIGEN.md).

Las pantallas ejecutan las consultas externas en una cola exclusiva. Antes de desplegar,
activar el proceso descrito en [Integraciones en cola](INTEGRACIONES_EN_COLA.md).
