# Hidrocooler MP en tablet

La tablet consulta y opera los mismos lotes y ciclos que Oficina. No existe una copia
local del estado del Hidrocooler: la conexión al servidor es necesaria para iniciar,
finalizar, liberar o descargar registros.

## Accesos

- En **Accesos y temporadas**, el perfil debe tener **Materia Prima → Hidrocooler**
  en Oficinas PC y **Hidrocooler MP** en módulos de tablet.
- La migración `2026_09_29_180000_habilitar_hidrocooler_mp_en_tablet` añade el módulo
  móvil a los perfiles predeterminados que ya tenían Hidrocooler en Oficina. Los
  perfiles personalizados conservan sus elecciones; el administrador puede
  habilitarles la casilla de tablet manualmente.
- El usuario debe cerrar e iniciar sesión en la tablet para recibir el módulo y
  su permiso en un token nuevo. Consulta solo ve bandejas y registros;
  operación inicia y cierra; **Evaluar y liberar** exige además supervisión de
  lotes de materia prima, igual que en Oficina.

## Funciones

- Pendientes, en curso, retenidos e historial de la temporada activa, con
  búsqueda, filtros y paginación.
- Resumen de lotes, kilos, ciclos y duración; detalle de los controles de agua,
  temperaturas, observaciones y motivos de retención.
- Inicio, cierre y liberación con los mismos campos y validaciones del backend.
  Cada envío conserva su `operacion_id` si falla y se reintenta desde el formulario.
- Registros PDF y Excel, llenos o en blanco, compartibles mediante el selector
  de archivos del dispositivo. Se descargan usando el token del usuario y se
  guardan en la caché local de la app antes de compartirlos.

## Despliegue

Se agregan `expo-file-system` y `expo-sharing`, módulos nativos. La versión de
la app y del runtime sube a **1.5.0** (`versionCode` Android **7**). Una OTA sobre
la APK 1.4.0 no puede habilitar la descarga de archivos. Tras fusionar: ejecutar
`php artisan migrate --force` en cada servidor; compilar e instalar una nueva
APK `apk-camaras` para las tablets y publicar la OTA de producción con runtime
1.5.0 cuando corresponda. La PDA usa el canal `pda`; su APK 1.4.0 y las
actualizaciones de ese runtime no cambian por publicar en `production`.

Prueba de aceptación: entrar con un perfil autorizado, comprobar bandejas y
filtros; iniciar un lote pendiente con controles conformes, cerrarlo y verificar
el destino en Oficina. Para una desviación, comprobar que queda retenido y que
solo supervisión puede evaluarlo y liberarlo. Descargar PDF y Excel en un
dispositivo con la nueva APK y abrirlos desde otra aplicación.
