# FoliOS móvil

Cliente nativo para tablets Android, construido con Expo, React Native y TypeScript. Reúne la operación física de cámaras, cargas, recepción de materiales e impresión de etiquetas desde terreno.

## Requisitos locales

- Node.js 24 (la versión usada por CI).
- npm.
- Expo Go compatible con el SDK indicado en `package.json`, en una tablet Android o un emulador, salvo al probar funciones nativas como la impresión por IP.

PHP, Composer y MySQL no son necesarios para trabajar únicamente en esta carpeta. Serán necesarios cuando se quiera ejecutar el backend Laravel en el mismo equipo.

## Servidor Laravel configurable desde la tablet

La APK no queda amarrada a una IP. En la pantalla de acceso, pulsa **Configurar servidor** e ingresa la dirección del computador que ejecuta Laravel:

```text
10.16.104.25:8000
```

También acepta una URL completa, por ejemplo `https://wms.empresa.cl`. Si no se escribe el protocolo, la aplicación utiliza `http://`. La dirección normalizada queda almacenada en la tablet y se conserva al cerrarla o reiniciarla.

`EXPO_PUBLIC_API_URL` continúa disponible como valor inicial opcional para desarrollo, pero ya no es obligatorio para la APK.

1. Desde la raíz, inicia Laravel en la red local:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

2. Para desarrollo, inicia Expo:

```bash
cd mobile
npm ci
npm run start:clear
```

3. Abre la aplicación en Expo Go, configura el servidor y verifica que el encabezado diga **API conectada**, no **Demo local**.

Desde la tablet, `localhost` apuntaría a la propia tablet. El computador y la tablet deben estar en la misma red y el firewall debe permitir Node.js y PHP en la red privada.

La guía completa para preparar MySQL, ejecutar un movimiento y comprobarlo directamente en la base está en [`docs/prueba-escritura-mysql.md`](../docs/prueba-escritura-mysql.md).

## APK instalable y actualizaciones automáticas

La aplicación utiliza EAS Build para generar una APK firmada de distribución interna y EAS Update para actualizar automáticamente JavaScript, estilos e imágenes. La APK comprueba actualizaciones al arrancar y, si encuentra una compatible, la descarga y reinicia usando la última versión válida como respaldo.

Vinculación inicial —se realiza una sola vez con la cuenta Expo propietaria del proyecto—:

```bash
cd mobile
npx eas-cli@latest login
npm run eas:configure
```

Ese comando agrega a `app.json` el `projectId` y la URL segura de EAS Update. Después se genera la APK:

```bash
npm run build:apk
```

Para publicar cambios de interfaz o lógica que no agreguen dependencias nativas:

```bash
npm run update:production -- --message "Descripción del cambio"
```

Cambios nativos —por ejemplo instalar otra biblioteca nativa, modificar permisos o subir la versión de Expo— requieren incrementar la versión, generar una APK nueva e instalarla. EAS Update no reemplaza silenciosamente el binario Android.

La impresión directa de etiquetas utiliza un módulo Android local que abre una conexión RAW TCP con la impresora configurada (puerto `9100` por defecto). Por eso debe probarse con la APK `1.2.0` o superior: Expo Go y una APK anterior muestran la interfaz, pero no pueden abrir el socket de impresión.

## Dos APK: tablet y PDA

El mismo código genera dos aplicaciones Android que pueden convivir en un mismo equipo:

| | Tablet | PDA |
| --- | --- | --- |
| Nombre | FoliOS | FoliOS PDA |
| Paquete Android | `cl.estiba.wms.camaras` | `cl.estiba.wms.pda` |
| Perfil EAS | `apk-camaras` | `apk-pda` |
| Canal de actualizaciones | `production` | `pda` |
| Módulos | todos los del perfil | solo Validación PT y Validación MP |
| Orientación | según módulo | siempre vertical |

La PDA de referencia es la **Unitech EA520** (5", 1280×720, Android 11, lector SE4710): unos 360×568 dp útiles. En esa variante Validación PT y MP usan una cabecera compacta, el contexto de línea y turno se contrae después de elegirlo, los selectores se ordenan de a dos por fila y las métricas de sesión caben de a dos. Aunque el perfil del usuario tenga más módulos, la PDA solo ofrece Validación PT, Validación MP y Repaletizaje. Si no tiene ninguno, indica que debe usar la tablet.

**Repaletizaje (tarjador):** el rol `Tarjador` entra directo a Repaletizaje. La pantalla pide el turno (A/B) una vez por equipo y lo recuerda; el lector agrega saldos y muestra en qué cámara y posición está cada uno. Cada repa confirmada llena el registro RRPL-01, que la oficina descarga en Frigorífico → Repaletizajes. Requiere conexión: la repa se confirma en el servidor en el momento.

```bash
npm run build:apk        # tablet (perfil apk-camaras)
npm run build:apk:pda    # PDA (perfil apk-pda)
npm run build:apk:pda:pruebas  # PDA de pruebas (canal pda-pruebas)

npm run update:production -- --message "..."   # solo tablets
npm run update:pda -- --message "..."          # solo PDA
npm run update:pda:pruebas -- --message "..."  # solo PDA de pruebas

npm run start:pda        # Metro con la variante PDA para probar en Expo Go o en un build de desarrollo
```

La variante se decide por el canal grabado en la APK instalada. Por eso una actualización publicada en el canal equivocado no convierte una tablet en PDA ni al revés. Sin canal (Expo Go, `expo start`) se usa `EXPO_PUBLIC_APP_VARIANT`. Un cambio que deba llegar a ambos equipos se publica en los dos canales. Cambios nativos requieren generar e instalar las dos APK.

Para probar una OTA en `pda-pruebas`, la PDA de pruebas debe tener instalada la APK creada con `apk-pda-pruebas`. La APK habitual `apk-pda` escucha el canal `pda` y no recibe esas actualizaciones. Ambos perfiles usan el mismo paquete Android; instalar la APK de pruebas sustituye la habitual en ese dispositivo. Tras publicar la OTA, cierra y abre la aplicación para que compruebe la actualización.

### Lector integrado de la PDA

Los campos de folio (PT) y de correlativo REC (MP) esperan el lector en modo teclado. El campo no abre el teclado en pantalla al recibir el foco y conserva el foco después de cada lectura. El botón ⌨ habilita el teclado para digitar a mano una etiqueta ilegible. El código leído se normaliza: se quitan saltos de línea, tabulaciones y espacios, y se pasa a mayúsculas.

Configuración en la EA520, en **Ajustes → Scanner Settings** (el nombre puede variar según el firmware):

1. Salida de datos: **Keyboard emulation / Keystroke** (no Intent ni portapapeles).
2. Sufijo: **Enter** (`CR` o `LF`); con eso cada lectura dispara la consulta.
3. Sin prefijo, y el formato GS1 o el code set de la etiqueta de folio habilitado.
4. Opcional: desactivar el sonido del sistema si el lector ya emite su propio pitido.

## Modo demostración explícito

El simulador local de desarrollo sigue disponible, pero debe habilitarse deliberadamente:

```bash
EXPO_PUBLIC_DEMO_MODE=true
EXPO_PUBLIC_API_URL=
```

Después de cambiar el modo, reinicia Metro:

```bash
npm run start:clear
```

Los clientes y folios creados en el modo demo quedan almacenados en SQLite dentro del dispositivo y nunca llegan a MySQL.

## APK autónoma para demostraciones

La variante **FoliOS Demo** utiliza el paquete Android `cl.estiba.wms.demo`, por lo que puede instalarse junto a la APK productiva. No permite configurar una URL de Laravel, deshabilita EAS Update y guarda sus datos exclusivamente en `estiba-wms-demo.db` dentro de la memoria privada de la tablet.

Incluye un escenario ficticio inicial y una administración local desde la que se pueden:

- consultar y ampliar una base maestra precargada con temporadas, proveedores, especies,
  variedades, calibres, envases, materiales, destinos, túneles y perfiles de impresión;
- crear y eliminar clientes;
- crear y eliminar folios con especie, variedad y cajas;
- conservar los cambios después de cerrar o reiniciar la app;
- preparar otra demostración sin borrar los maestros agregados;
- restaurar toda la base inicial cuando se necesite borrar también las personalizaciones.

### Política de datos comerciales

La precarga conserva la **estructura útil de los maestros**, pero utiliza identidades ficticias.
La APK Demo no incorpora una copia de los datos operativos de Laravel/MySQL.

| Se conserva en SQLite Demo | Se excluye siempre de la precarga |
| --- | --- |
| temporadas y catálogos de producto | usuarios, correos, contraseñas y tokens |
| especies, variedades, calibres y envases | folios reales y sus ubicaciones actuales |
| materiales, destinos y centros de costo ficticios | inventario, saldos y deudas reales |
| configuración ficticia de túneles e impresión | recepciones, validaciones y procesos activos |
| clientes y maestros creados en la propia tablet | cargas, despachos, retornos y movimientos reales |

Los registros agregados desde **Administración Demo** quedan marcados como locales y persisten
entre cierres y reinicios. **Preparar nueva demo** restaura únicamente el escenario operacional
ficticio y conserva esos maestros. **Restaurar todo** elimina también las personalizaciones y
regresa al catálogo seguro incluido en la APK.

El motor operativo local también permite:

- abrir y cerrar sesiones de estiba sin Laravel;
- buscar en cámaras los folios creados en Administración Demo;
- ubicar folios en posiciones libres;
- reubicar dentro de una cámara o trasladar entre cámaras;
- conservar planos, sesiones e historial de movimientos después de reiniciar la app;
- rechazar operaciones con una versión antigua del plano;
- aceptar reintentos idénticos sin duplicar movimientos y rechazar UUID reutilizados con otro contenido.

El flujo local de cargas de frío permite además:

- crear borradores `CAR-xxxxxx` desde **Administración Demo** con hasta 26 folios ubicados;
- mantener cada folio en una única carga activa y liberarlo al cancelar;
- publicar la carga para que recién entonces aparezca en la bandeja operacional;
- cambiar su prioridad con incremento de versión y trazabilidad;
- recibir banner, badge y notificaciones locales al publicar o cambiar una prioridad;
- abrir el detalle, la secuencia de extracción y los folios resaltados en el plano;
- conservar la asignación `CAR-xxxxxx` al reubicar o trasladar un folio;
- reportar incidencias y enviar folios a un andén con operaciones idempotentes;
- conservar cargas, alertas e historial después de cerrar o reiniciar la APK.

El botón **Preparar nueva demo** reinicia folios, cámaras, posiciones, sesiones, movimientos,
cargas, notificaciones y auditoría local, pero conserva clientes y maestros. El botón
**Restaurar todo** reinicia además clientes y catálogos personalizados.

Credenciales sugeridas —el modo local sólo exige que los tres campos estén completos—:

```text
Correo: administrador@folios.demo
Contraseña: password
Tablet: DEMO-01
```

Para ejecutar la variante en desarrollo:

```bash
cd mobile
npm ci
npm run start:demo
```

Para validar el bundle autónomo o solicitar una APK interna a EAS:

```bash
npm run export:demo
npm run build:apk:demo
```

`expo-sqlite` es una dependencia nativa. La primera instalación de esta variante requiere generar una APK nueva; no puede agregarse a una APK anterior por OTA. Al desinstalar **FoliOS Demo**, Android también elimina su base local.

Prueba manual mínima de persistencia comercial y del motor local:

1. Agrega un dato maestro y un cliente desde **Administración Demo**.
2. Crea un folio, cambia a **Operación frigorífico** y abre `CAM-01`.
3. Selecciona una posición libre, busca el folio creado y confirma su ubicación.
4. Muévelo a otra posición y comprueba el historial.
5. Cierra por completo la aplicación, vuelve a entrar y confirma que los datos permanecen.
6. Regresa a **Administración Demo** y utiliza **Preparar nueva demo**.
7. Confirma que volvió el escenario operativo inicial y que el maestro y el cliente agregados siguen presentes.
8. Utiliza **Restaurar todo** y confirma que las personalizaciones desaparecen.

Prueba manual del flujo de cargas local:

1. En **Administración Demo**, selecciona entre 1 y 26 folios ubicados y crea un borrador.
2. Confirma que el borrador no aparece todavía en **Operación frigorífico → Cargas**.
3. Publica la carga y entra en **Operación frigorífico**.
4. Comprueba el banner, el badge de alertas y el acceso directo a la nueva `CAR-xxxxxx`.
5. Abre la carga y verifica su detalle, ruta y folios resaltados con el código CAR en el plano.
6. Mueve uno de sus folios dentro de la cámara o hacia otra cámara y confirma que conserva la misma carga.
7. Cambia la prioridad desde Administración Demo y comprueba que la versión y la alerta se actualizan.
8. Crea otra carga, cancélala antes de despachar y confirma que sus folios vuelven a estar disponibles.
9. Cierra completamente la aplicación, vuelve a entrar y comprueba que cargas y notificaciones permanecen.

## Validaciones

```bash
npm run typecheck
npx expo-doctor@latest
npm run export:android
```

GitHub Actions ejecuta ambas validaciones en cada pull request.

## Alcance actual

- Login por email, contraseña y código del dispositivo.
- Dirección de Laravel configurable y persistente en cada tablet.
- APK independiente con perfil de distribución interna.
- Actualización OTA automática para interfaz y lógica compatible.
- APK Demo separada, sin servidor externo, con clientes y folios persistentes en SQLite.
- Confirmación visual después de guardar una ubicación o movimiento en el servidor.
- Errores operacionales visibles dentro de los modales.
- Actualización automática del plano cada 30 segundos y al volver a la aplicación.
- Selector de cámaras, ocupación, bloqueo y modo de solo lectura.
- Apertura y cierre de sesiones de estiba.
- Ubicación inicial con datos del folio y condición SAG.
- Reubicación dentro de una cámara y traslado entre cámaras.
- Historial de movimientos recientes.
- Bandeja compartida de cargas de frío con prioridad, incidencias y concentración.
- Ruta de extracción calculada desde la entrada hacia el fondo sobre el plano vertical.
- Reporte de incidencias físicas desde terreno.
- Envío individual o secuencial de folios a un andén.
- Centro de notificaciones persistentes con lectura y confirmación individual.
- Polling resiliente cada 12 segundos para alertas, cargas y rutas de extracción.
- Recepción de materiales con folios trazables.
- Selección de uno, varios o todos los folios de una recepción confirmada.
- Perfiles ZPL por fabricante, modelo, formato y DPI para equipos Zebra y Bixolon compatibles.
- Impresora por IP configurable y persistente por dispositivo, con prueba de conexión y resultado auditable.

La APK conectada todavía no incluye persistencia offline general ni sincronización diferida. Durante una interrupción conserva el último estado descargado, pero las operaciones nuevas continúan requiriendo conexión con Laravel. La persistencia SQLite descrita arriba corresponde exclusivamente a la variante Demo y se ampliará módulo por módulo. Un envío de impresión que pierde confirmación después de comenzar se marca como indeterminado y nunca se reintenta automáticamente.
