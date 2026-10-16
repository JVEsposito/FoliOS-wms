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
5. Si falla, revisar los datos, la conectividad de red desde ese servidor y que
   PHP tenga pdo_mysql. La respuesta no incluye el error técnico del controlador.

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
