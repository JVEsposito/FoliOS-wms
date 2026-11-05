# PR 13 · Tablet: tipografía, Ambiente y plano de estiba

**Rama:** `claude/tablet-revision-tipografia-ambiente-plano` · **Base:** `main` · **Entrega:** implementación
**Título del PR:** `feat(tablet): tipografía de terreno, Ambiente oscuro y plano legible`

Revisión en planta de #577 y #578:

1. la fuente no era la de la lámina;
2. el plano de estiba no había cambiado;
3. el control ambiental se veía peor.

Va en un PR con tres commits, uno por tema. Los tres tocan la tipografía, y separarlos en PRs
habría obligado a encadenarlos, cosa que la auditoría de base no admite.

## 1 · Tipografía Inter y JetBrains Mono

- `@expo-google-fonts/inter` (400, 500, 600, 700, 800) y `@expo-google-fonts/jetbrains-mono`
  (500, 700) como archivos `.ttf` estáticos dentro del paquete JS.
- `expo-font` pasa a dependencia directa con la **misma versión** que ya traía `expo` (57.0.1).
  El módulo nativo es el mismo del APK instalado, así que llega por OTA. Esto resuelve el PR 10,
  que quedó opcional, con la tipografía de la lámina en lugar de Archivo / Azeret.
- `App.tsx` espera `useFonts` junto con la configuración inicial. Si la carga falla, sigue con
  la fuente del sistema.
- `theme/typography.ts` define una familia por peso (`font.regular` … `font.heavy`,
  `font.mono`, `font.monoBold`). En React Native, `fontWeight` no elige el archivo de una fuente
  cargada, así que los estilos ya no llevan `fontWeight`.
- Archivos que cambian:
  - pantallas del camarero, plano, cargas, `ActionPanel`, `OperationModals` y
    `NotificationCenter`;
  - `operatorTheme.font.condensed` y `operatorTheme.font.mono` apuntan a las nuevas familias.
- Los estilos que solo cambian el tamaño (por ejemplo `titleCompact`) heredan la familia del
  estilo base.
- **Cola de la portada:** el contador «Disponibles · 1» incluye la maniobra destacada. Si no hay
  otra, ahora dice «No hay más maniobras en cola» en lugar de «No hay tareas disponibles».

## 2 · Control ambiental

La pantalla usaba `EstibaPanel`, `EstibaButton` y `EstibaField`, que son claros (Oficina), sobre
el fondo oscuro de la tablet. Por eso los títulos y los códigos `CAM-xx` quedaban invisibles.

- **Encabezado de una línea:** cuántas cámaras requieren control, cuántas están al día, la hora
  de consulta y ↻. Se quitan las tres tarjetas de resumen.
- **Lista de pendientes**, con las vencidas primero. Cada fila muestra:
  - estado y hora;
  - la última lectura (Inicio, Medio, Fondo en JetBrains);
  - el botón «Registrar».
- **Cámaras al día** quedan plegadas en una fila.
- **Captura a la derecha:** tres campos de 64 dp en monoespaciada y el mismo flujo de revisar,
  confirmar, reintentar el mismo envío y descartar el intento. El responsable y el equipo pasan a
  una nota al pie.
- No cambian el borrador local, el UUID, la hora capturada ni la consulta cada 60 s.

## 3 · Plano de estiba

**`PositionMap`**
- **Cada dato una vez:** la banda en su cabecera (B25), la posición en una columna izquierda
  (P01…) y el nivel en un selector táctil de 44 dp. La etiqueta `B25-P01-N1` queda solo en la
  lectura accesible.
- **Celda libre vacía.** Se quitan «LIBRE», «Disponible» y «○».
- **Celda ocupada:** el folio con los últimos 4 dígitos destacados y la variedad. Si el pallet
  va a una carga, el código CAR reemplaza la variedad. El saldo se marca con «S».
- **Cabecera de banda:** «13/13 libres». El estado solo se muestra cuando es Bloqueada, En
  vaciado o Completa. La afinidad aparece solo si existe, sin la línea «Sin afinidad · banda
  liberada». Los usos aparecen solo si están restringidos.
- **Textos ≥ 12 dp.** Antes había de 6 a 9 dp.
- Leyenda: se agrega «Reservada» cuando hay posiciones reservadas.

**`CameraCard`**
- El nombre va en su propia línea, hasta dos líneas, porque se cortaba en «Camara d…». El estado
  y el contenido van debajo.

**`OperationalScreen`**
- «API conectada» solo aparece cuando no hay conexión o en modo demostración; la cabecera ya
  dice «Conectado».
- Se retira el «Salir» de esta vista. El menú del operador lo reemplaza y, en «Plano y
  operación», usa el mismo cierre de esta vista (`onRegisterLogout`), que avisa si quedan
  **estibas abiertas**. Esto también corrige un hueco de #577: el menú cerraba sesión sin esa
  revisión.

## Pruebas

- Nueva: `tests/JavaScript/TabletRevisionTest.mjs`.
- Actualizada: `OperatorHeaderCompactTest.mjs`.
- Arneses: `gatehouse.test.mjs` (simula `expo-font` y `fontAssets`), `materialVerification`
  y `operatorPresence` (simulan `theme/typography`).
