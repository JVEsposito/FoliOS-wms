# POEMP-R3 · Registro control hidrocooler

El formato oficial inicial es versión 2, 31-08-2026, localidad Rengo. Se reemplazan ambas exportaciones anteriores. Cada ciclo nuevo congela su encabezado al iniciarse; los históricos sin encabezado usan la plantilla oficial inicial v2, sin rellenar sus campos nuevos. El formulario en blanco usa el formato actualmente activo.

## Pendiente de planta antes de fusionar

«Corrección» se interpreta como lectura en ppm después de corregir. El rango numérico SOP todavía no está validado por planta. Configurar `HIDROCOOLER_CLORO_MIN_PPM` y `HIDROCOOLER_CLORO_MAX_PPM` exclusivamente con límites aprobados. No hay valores sanitarios predeterminados. Sin ambos límites se conserva la declaración explícita de conformidad del SOP, y Oficina/PDA muestran que el rango numérico está pendiente. Una configuración parcial o inválida se rechaza.

Con rango configurado, una lectura inicial fuera de rango exige corrección; la lectura corregida debe quedar dentro de rango. La conformidad declarada corresponde al agua antes de procesar fruta, después de cualquier corrección. El ciclo guarda también los límites usados. No se cambian los controles ni la retención del cierre.

## Despliegue

1. Desplegar backend y Oficina y ejecutar `php artisan migrate`.
2. Revisar POEMP-R3 en Administración → Formatos de registro.
3. Crear los productos reales en Administración → Productos hidrocooler; nombre, unidad de dosis sugerida y estado. No se precargan productos supuestos. Creaciones y modificaciones guardan actor y antes/después; actualizaciones exigen `version_conocida`.
4. Configurar el rango aprobado y limpiar/reconstruir el caché de configuración.
5. Publicar OTA a `pda-pruebas` y reabrir. Un cliente anterior no puede iniciar ciclos sin los campos oficiales nuevos. Probar antes de distribuir a operación.

No se ejecutan despliegue ni OTA desde este PR.

## Documento y prueba conjunta

- El registro conserva los filtros de fechas, equipo, turno y búsqueda, e incluye ciclos completados y en curso. Los cancelados no se imprimen.
- PDF y XLSX se construyen desde las mismas hojas. 24 filas por hoja, agrupadas por encabezado congelado. 30 ciclos breves de la misma versión producen dos hojas, 24 + 6, con encabezados y firmas en ambas.
- Papel A3 horizontal para mantener legibles las 22 columnas. XLSX conserva área de impresión y ajuste de una hoja por página. Firma Jefe de Calidad y Responsable vacías. Textos de celdas muy extensos pasan a notas identificadas por lote; notas largas usan hojas de continuación idénticas en ambos formatos.
- Productor se obtiene del CSG del lote, no del cliente exportador. Hora llegada corresponde al ingreso en Romana; responsable al usuario de inicio. Los datos de producto aplicados se congelan con nombre, dosis y unidad.
- Ciclos previos: campos nuevos en blanco. Ciclo en curso: salida y exposición en blanco. Documento en blanco: logo, encabezado vigente y 24 filas vacías.
- Probar campos obligatorios, aplicación Sí/No, producto inactivo, cloro fuera de rango, reintento idempotente y reimpresión tras modificar el maestro.
- Exportar 30 ciclos, mezclar versiones, probar observaciones largas y comprobar PDF/XLSX. Los filtros de equipo y turno no se imprimen como columnas.
- El reinicio operacional conserva catálogo, historial de productos y formatos documentales.

## API

- `GET /api/materia-prima/hidrocooler/productos`: productos activos y rango SOP, autorizado por consulta hidrocooler.
- `/api/administracion/productos-hidrocooler`: GET/POST; `/{producto}` PUT y `/{producto}/eventos` GET paginado, administración de accesos.
- Inicio de ciclo existente agrega ambiente, HR, mV, recarga, corrección, aplicación, producto, dosis y unidad. El servidor obtiene nombre y formato; el cliente no decide sus snapshots.
