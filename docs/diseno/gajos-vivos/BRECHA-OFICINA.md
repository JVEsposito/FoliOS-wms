# Brecha de Oficina frente a Gajos vivos

Comparación pantalla por pantalla de las 82 oficinas de `main` con [Gajos vivos](README.md). Es la base para cerrar la brecha que dejaron los PR #707 y #710.

**Medición:**
- **Fecha:** 22-11-2026.
- **Base:** `main` en `2c20a51e`.
- **Inventario:** el de [README.md](README.md#inventario-de-pantallas).
- **Método:** revisión del código, no de pantallas en ejecución. Para cada oficina de `CatalogoOficinas` se leyeron su vista Blade, los parciales incluidos, los scripts que carga con sus imports y su CSS. Se excluyó el armazón compartido (menú, arranque y scripts de sesión) para que no cuente como parte de cada pantalla.
- **Sin verificar:** el contraste en claro y oscuro, en todas las pantallas. Hay que confirmarlo a la vista.

## Resultado

Ninguna de las 82 oficinas está en Gajos vivos según la [definición de terminado](README.md#definición-de-terminado-por-pantalla). Tres faltas afectan a todas:

- No existen la perforación ni el troquel.
- Ninguna pantalla usa los glifos de estado (● ◐ ○ ◆ ■).
- Ninguna tiene encabezado etiqueta con línea de código.

Las más cercanas son Operación ahora, el Panel gerencial, Prefrío, Cargas y Estatus operativo. Son las únicas cinco que usan código con prefijo apagado (`officeCode()`) y celdas (`palletCells()`), ambos de `resources/js/shared/folios-display.js`.

## Definición de terminado: lo que falla en todas

| Requisito | Estado en `main` |
| --- | --- |
| Barra de contexto y regla del área | ◐ La barra única existe, pero no dice «En línea · lectura hace X s». Además, 40 pantallas vuelven a mostrar la temporada. |
| Encabezado como etiqueta: hoja del área, título y línea de código | ✗ En las 82. |
| Paneles como etiquetas con troquel; indicadores como tickets con talón | ✗ En las 82. Hay tickets con talón (`.folios-ticket`) en 9 pantallas, sin perforación ni troquel. |
| Códigos en mono con prefijo apagado; folio con los últimos cuatro destacados; patente como placa | ◐ En 5 pantallas, ✗ en 77. La placa de patente no existe en ninguna. |
| Estado con glifo y texto; ningún color de área en estados | ✗ En las 82. Los estados se marcan con color (`x-estiba.signal`) o con texto. |
| Celdas en lo que se cuenta | ◐ En 5 de las 44 pantallas donde aplica. |
| Escala de frío | ◐ En 1 de las 12 pantallas donde aplica (Panel gerencial), con tonos calculados y no los cinco del documento. |
| Una sola acción principal por vista | Solo hay un indicio: 63 pantallas tienen más de un botón principal en el marcado. El conteo incluye diálogos y pestañas ocultas, así que hay que confirmarlo a la vista. |
| Sin gajos decorativos | ✗ En 77. En 76, el rótulo del encabezado (`[data-folios-masthead]`) lleva un gajo de adorno mediante `--area-gajo` (`resources/css/office-folios.css`). La Demo pone gajos en sus pestañas, y el portal de clientes también, aunque el portal no está en el inventario. |
| Estados vacío, cargando, error y sin conexión | El estado sin conexión está en solo 5 pantallas. Vacío y cargando existen en la mayoría, pero no con la forma de la etiqueta. |

## Por pantalla

Columnas 1 a 7: las reglas de [«Lo importante»](README.md#lo-importante).

1. El gajo informa.
2. Cada pantalla es una etiqueta.
3. El código es tipografía.
4. Estados con glifo.
5. Un pallet, una celda.
6. Escala de frío.
7. El contexto se dice una vez.

Leyenda: ✓ cumple · ◐ parcial · ✗ no cumple · — no aplica según el inventario o el contenido de la pantalla.

| Área | Pantalla | Tipo | 1 | 2 | 3 | 4 | 5 | 6 | 7 | Notas |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| MP | Resumen | Resumen de área | ✗ | ◐ | ✗ | ✗ | ✗ | — | ◐ | sin gajo de atención |
|  | Ingresos Portería | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 2 botones principales; tiene estado sin conexión |
|  | Romana | Bandeja + Captura | ✗ | ◐ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 12 botones principales |
|  | Defectos de recepción | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 2 botones principales |
|  | Digitación de Lotes | Captura + Bandeja | ✗ | ✗ | ✗ | ✗ | — | ✗ | ✗ | repite temporada; 3 botones principales |
|  | Recepción por CSG | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada |
|  | Hidrocooler | Captura + Bandeja | ✗ | ✗ | ✗ | ✗ | — | ✗ | ✗ | repite temporada; 4 botones principales |
|  | Órdenes de Proceso | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 3 botones principales |
|  | Fruta a Proceso | Captura + Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |
|  | Despacho Comercial | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 3 botones principales |
|  | Existencias MP | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ |  |
|  | Cuenta Envases | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 13 botones principales |
|  | Despacho Envases | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 4 botones principales |
| FR | Resumen | Resumen de área | ✗ | ◐ | ✗ | ✗ | ✗ | — | ◐ | sin gajo de atención |
|  | Validación | Captura + Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 3 botones principales |
|  | Pendientes por validar | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |
|  | Etiquetas PT | Impresión | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 3 botones principales |
|  | Recepción de fruta embalada | Captura + Bandeja | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | sin gajo de adorno; repite temporada; 3 botones principales |
|  | Repaletizajes | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 3 botones principales |
|  | Anulaciones | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 2 botones principales |
|  | Contraparte SAG | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 9 botones principales |
|  | Lotes retenidos SAG | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 3 botones principales |
|  | Inspección SAG | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 2 botones principales |
|  | Diagramas SAG/USDA | Plano | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |
|  | Prefrío | Tablero + Bandeja | ✗ | ◐ | ◐ | ✗ | ◐ | ✗ | ◐ | 8 botones principales |
|  | Punto de rocío | Tablero | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ◐ | 7 botones principales |
|  | Cámaras | Plano | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | repite temporada; 3 botones principales |
|  | Control de personal | Calendario + Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | tiene estado sin conexión |
|  | Labores por turno | Calendario | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | tiene estado sin conexión |
|  | Discrepancias | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ |  |
|  | Cargas | Bandeja + Detalle | ✗ | ✗ | ◐ | ✗ | ◐ | — | ◐ | 13 botones principales |
|  | Estatus operativo | Tablero | ✗ | ◐ | ◐ | ✗ | ◐ | — | ◐ | tiene estado sin conexión |
|  | Calendario de embarques | Calendario | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 4 botones principales |
|  | Control de despacho (R-24) | Impresión + Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ◐ | 4 botones principales |
|  | Existencias PT | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ |  |
| MA | Resumen | Resumen de área | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | sin gajo de atención; repite temporada; 25 botones principales |
|  | Catálogos | Configuración | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Ítems · niveles de stock | Configuración | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 2 botones principales |
|  | Ítems | Configuración + Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada |
|  | Recepciones | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Etiquetas | Impresión | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Inventario BC | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Capacidad de bodegas | Plano + Tablero | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 6 botones principales |
|  | Supervisión | Tablero | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada |
|  | Vencimientos | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Reposición | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 2 botones principales |
|  | Tomas de inventario | Captura + Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 2 botones principales |
|  | Inventario CC | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 3 botones principales |
|  | Despachos | Bandeja + Captura | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Recetas | Configuración | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Estándares de consumo | Configuración + Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 5 botones principales |
|  | Rebaja por estándar | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 2 botones principales |
|  | Consumos por OT | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada |
|  | Órdenes | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 25 botones principales |
|  | Existencias | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ |  |
| AD | Resumen | Resumen de área | ✗ | ◐ | ✗ | ✗ | ✗ | — | ◐ | sin gajo de atención |
| Centro | Operación ahora | Resumen de área | ◐ | ◐ | ◐ | ✗ | ◐ | ✗ | ✗ | gajo de atención calculado en el navegador, no en el backend; sin gajo de adorno; repite temporada; tiene estado sin conexión |
| GE | Panel Gerencial | Tablero | ✗ | ◐ | ◐ | ✗ | ◐ | ◐ | ✗ | repite temporada |
| AD | Accesos & Temporadas | Configuración | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 19 botones principales |
|  | Plantas y umbrales de prefrío | Configuración | ✓ | ✗ | ✗ | ✗ | — | ✗ | ◐ | sin gajo de adorno; 2 botones principales |
|  | Maestros de temporada | Configuración | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 8 botones principales |
|  | Configuración de cámaras | Configuración + Plano | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | repite temporada; 3 botones principales |
|  | Salud operacional | Tablero + Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 2 botones principales |
|  | Cambio de atributos PT | Captura | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 5 botones principales |
|  | Demo comercial | Configuración | ✗ | ✗ | ✗ | ✗ | — | ✗ | ✗ | gajos de adorno en pestañas; repite temporada; 4 botones principales |
|  | Hechos valorizables | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 2 botones principales |
|  | Tarifario | Configuración | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 9 botones principales |
|  | Proveedores | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 15 botones principales |
|  | Costos COMEX | Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 4 botones principales |
|  | Prefactura | Detalle + Bandeja | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 3 botones principales |
|  | Personal y dotación | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 31 botones principales |
|  | Puesto de marcaje | Captura | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 31 botones principales |
|  | Expedientes de embarque | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 2 botones principales |
|  | Configuración Comex | Configuración | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 3 botones principales |
|  | Actores y lugares | Configuración | ✗ | ✗ | ✗ | ✗ | — | — | ◐ | 4 botones principales |
|  | Notas de venta | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | — | — | ✗ | repite temporada; 4 botones principales |
|  | Bookings y contenedores | Bandeja + Detalle | ✗ | ✗ | ✗ | ✗ | ✗ | — | ◐ | 4 botones principales |
| CO | Resumen | Resumen de área | ✗ | ◐ | ✗ | ✗ | ✗ | — | ◐ | sin gajo; el documento no le da cifra por ahora |
|  | Búsqueda Operacional | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |
|  | Trazabilidad de lotes | Detalle | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |
|  | Productores SAG / CSG | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |
|  | Productores Verificados | Bandeja | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ | repite temporada; 4 botones principales |

En la columna 7, ◐ significa que la pantalla no repite la temporada, pero la barra común sigue sin el estado «En línea».

El número de botones principales es el de toda la vista. Las nueve oficinas de Materiales comparten `office.materials` y las dos de RRHH comparten `office.rrhh`, así que en ellas el conteo es el de la vista común.

Repaletizajes y Anulaciones se registran desde `RepaletizajeServiceProvider` y `AnulacionValidacionPalletServiceProvider`, no desde `routes/`.

## Orden de trabajo

Sigue el [plan de adopción](README.md#plan-de-adopción). Ninguno de estos pasos cambia lógica, datos ni permisos.

1. **Piezas base, una sola vez.**
   - Componentes `x-folios.*`, con sus equivalentes en JS para las pantallas que se arman desde scripts:
     - etiqueta: talón, perforación y troquel;
     - ticket;
     - código: prefijo apagado, últimos cuatro dígitos del folio y patente como placa;
     - estado con glifo;
     - celdas: sólida, contorno, rayada y libre;
     - escala de frío.
   - Un mapa central que asigne cada valor de los enums `Estado*` a uno de los cinco glifos.
   - Los cinco tonos de la escala de frío en `design/estiba.tokens.json`.
2. **Marco común de las 82 oficinas a la vez.**
   - Barra de contexto con «En línea · lectura hace X s».
   - Retirar la temporada repetida de las 40 pantallas que la muestran.
   - Encabezado etiqueta con línea de código y pestañas con contadores.
   - Eliminar `--area-gajo` y los gajos de las pestañas.
   - Cifras en el menú en todas las oficinas, no solo en Operación ahora.
3. **Gajo de atención desde el backend.** Un endpoint de solo lectura con el conteo por área, armado con conteos que el backend ya calcula. Alimenta el inicio, el menú y Operación ahora, y reemplaza el cálculo que Operación ahora hace hoy en el navegador. La interfaz no define umbrales.
4. **Un tipo de pantalla a la vez,** migrando su componente común y luego sus pantallas:
   1. Bandeja: 51 pantallas la incluyen.
   2. Detalle: 13.
   3. Captura: 16.
   4. Resumen de área: 6. Necesita el paso 3.
   5. Tablero: 7. Aquí se cierran Prefrío y el Panel gerencial según su especificación.
   6. Plano: 4.
   7. Calendario: 3.
   8. Configuración: 13.
   9. Impresión: 3. Solo la pantalla; el documento impreso conserva su formato.
5. **Inicio y acceso, y después tablet y PDA,** con su propio informe de brecha.

## Decisiones que bloquean

- **Qué cuenta como «por atender» en cada área** ([fuentes sugeridas](README.md#el-gajo-de-atención-en-todas-las-áreas)). Sin esta definición, el paso 3 no puede implementarse sin inventar umbrales.
- **Los cinco tramos de la escala de frío.** El documento pide confirmarlos con Calidad, quizás por especie.
