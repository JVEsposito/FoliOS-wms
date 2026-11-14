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

## Registro de patio y registro completo

Al iniciar un ciclo, en la tablet y en Oficina, se elige el **tipo de registro**. Por omisión es el **registro de patio**.

- **Registro de patio** (`modalidad = patio`): pide solo tiempos, temperaturas y estado del agua.
  - **Al iniciar:** equipo, hora de inicio, temperatura de fruta al entrar, temperatura del agua (opcional), estado del agua (conforme / no conforme) y observación.
  - **Al terminar:** hora de término, temperatura de fruta al salir, temperatura del agua (opcional), estado del agua, destino (cámara o proceso) y observación.
  - No pide turno, bombas, temperatura objetivo, cloro, pH, ambiente, humedad, pozo, pastilla, producto ni dosificador. Si el cliente los envía, la API los descarta.
  - No depende de que el formato POEMP-R3 esté activo.
- **Registro completo POEMP-R3** (`modalidad = oficial`): el de siempre, sin cambios. Es también la modalidad de los ciclos anteriores y de las tablets que todavía no envían `modalidad`.
- **Reglas de patio:**
  - con agua no conforme no se inicia el ciclo, igual que en el registro completo;
  - si el agua termina no conforme, el lote queda retenido («Estado final del agua no conforme») y supervisión lo libera;
  - sin temperatura objetivo, la temperatura no retiene;
  - al liberar, cloro y pH verificados son opcionales (siguen obligatorios en ciclos oficiales).
- **Planilla POEMP-R3:** el registro PDF/Excel incluye los ciclos de patio con fecha, horas, lote, variedad, T° del agua, T° de ingreso y de salida, productor, tiempo de exposición, servicio y responsable. Las columnas de ambiente, HR, cloro, corrección, pastilla, producto, dosis, pH y mV quedan en blanco para que el área responsable las complete a mano. Las observaciones indican «Registro de patio: agua inicial … , final …». La planilla en blanco no cambia.
- **API:**
  - `POST …/hidrocooler/iniciar` acepta `modalidad`;
  - en `…/completar` y `…/liberar`, los campos de cloro, pH y controles son opcionales en la solicitud y el servicio los exige solo en ciclos oficiales;
  - el lote expone `hidrocooler.modalidad`;
  - migración `2026_11_14_090000_modalidad_ciclo_hidrocooler_materia_prima`.

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
