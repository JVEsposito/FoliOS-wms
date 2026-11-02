# Fundaciones visuales FoliOS

La dirección visual acordada es una interfaz corporativa de operación: estructura
azul marino, superficies claras, códigos completos y señales que ayudan a decidir.
Las maquetas de Cámara C3 y Detalle de carga orientan la composición. Sus cifras,
fotografías, planos y supuestas integraciones no son datos del producto.

## Alcance de esta entrega

Esta entrega prepara piezas reutilizables, un catálogo exportable y una muestra
nativa. Oficina adopta además la estructura compartida en todas sus pantallas;
los flujos especializados pueden profundizar la composición sin volver a crear
paleta, paneles, tablas o estados. No registra rutas web/API, no cambia permisos,
modelos o migraciones y no activa el planificador.

El catálogo usa los mismos componentes Blade que utilizarán las pantallas. Se
exporta sin base de datos, sesión, fuentes remotas o dependencias de red. Todos sus
registros son ficticios. No hay botones conectados a operaciones productivas.

## Una fuente de valores para Oficina y tablet

`design/estiba.tokens.json` es la fuente de color, espaciado, tipografía, radios y
densidad. `npm run design:tokens` genera:

- `resources/css/estiba-tokens.css`, limitado al contenedor `.estiba-ui`;
- `mobile/src/theme/estibaTokens.ts`, con valores numéricos para React Native.

`npm run design:check` detecta diferencias entre la fuente y los archivos
generados. CI lo ejecuta antes de compilar. Los archivos generados se versionan;
el despliegue no depende de ejecutar un generador en el dispositivo.

| Uso | Regla |
| --- | --- |
| Estructura | Azul marino `#183442`, reservado para marca y navegación. |
| Acción principal | Azul `#145DA0`, una acción principal por decisión. |
| Área de trabajo | Gris frío `#F3F5F7`, paneles blancos y bordes definidos. |
| Texto | `#182B3A`; texto secundario `#536777`. |
| Tipografía | Archivo (variable, con eje de ancho para títulos y cifras) y Azeret Mono para identificadores, autoalojadas: la planta no descarga fuentes de internet. Detalle en `docs/diseno/folios-v2/README.md`. |
| Identificadores | Monoespaciados; conservar el código completo y permitir salto de línea. |
| Tamaños de lectura | Cuerpo 16, secundario 14, encabezados 20/28; 12 solo metadatos. |
| Espaciado | 4, 8, 12, 16, 24, 32, 48. |
| Radios | Base 4/6; paneles operacionales de Oficina 2. Sin esquinas exageradas. |
| Profundidad | Bordes y superficies. Sin gradientes decorativos ni sombras de panel. |

La marca del producto es FoliOS. Empresa y planta son contexto configurable. La
firma “Frío que mantiene valor” forma parte del shell visual de referencia; no se
usa para inferir una empresa, planta ni estado operacional.

## Estados: significado antes que color

| Tono del componente | Uso | Ejemplo de texto |
| --- | --- | --- |
| `neutral` | Falta de registro o condición neutra | Sin registro |
| `info` | Proceso o consulta normal | En preparación |
| `success` | Confirmación o condición verificada | Pallet ubicado |
| `warning` | Atención requerida | Control vencido |
| `critical` | Emergencia o condición crítica | Emergencia activa |
| `reserved` | Recurso reservado | Reservado por maniobra |
| `temporary` | Pallet extraído bajo custodia | Extraído temporalmente |
| `blocked` | Bloqueo que impide continuar | Pausada por discrepancia |

Todos los estados llevan texto. Los indicadores añaden círculo, rombo o cuadrado;
el significado no depende de distinguir rojo de verde. No se pinta “ocupado” o
“en cámara” de rojo por defecto. Ocupación alta y prioridad no son sinónimos de
emergencia. Umbrales, estados y acciones permitidas vienen del dominio existente;
el componente visual no los deduce ni los modifica.

Una lista vacía solo se muestra después de una respuesta válida. Error de red,
consulta en curso y cero resultados son estados diferentes. “Sincronizado” requiere
evidencia del intercambio real y su última actualización, no solo conexión Wi-Fi.

Nunca se inventan temperatura, ETA, batería, posición del operario o porcentaje de
progreso térmico. Un dato desconocido se omite o muestra “Sin registro”. El cero
numérico sigue siendo cero. En los indicadores, una barra se acota entre 0 y 100%,
pero se conserva el conteo original si excede la capacidad.

## Componentes disponibles

| Oficina (Blade) | React Native | Responsabilidad |
| --- | --- | --- |
| `.estiba-ui` | `EstibaSurface` | Superficie y adopción del sistema. |
| `x-estiba.heading` | `EstibaHeading` | Título, contexto y acción principal. |
| `x-estiba.panel` | `EstibaPanel` | Agrupar una consulta o decisión coherente. |
| `x-estiba.button` | `EstibaButton` | Acción primary / secondary / critical / confirm. |
| `x-estiba.signal` | `EstibaSignal` | Estado explícito con texto e indicador. |
| `x-estiba.code` | `EntityCode` | Folio, carga o código de posición completo. |
| `x-estiba.table` | `EstibaTable` | Lectura tabular con encabezados y desplazamiento horizontal. |
| `x-estiba.field` | `EstibaField` | Etiqueta, entrada, ayuda y error contextual. |
| `x-estiba.metric` | `EstibaMetric` | Conteo y barra con valor accesible; admite ausencia de datos. |
| `x-estiba.alert` | `EstibaAlert` | Explicar una condición y su consecuencia. |
| `x-estiba.empty` | `EstibaEmpty` | Resultado vacío confirmado y siguiente acción. |
| `x-estiba.progress` | `EstibaProgress` | Secuencia proporcionada por la operación. |
| `x-estiba.icon` | — | SVG decorativos: almacén, pallet, flecha, confirmación, aviso, etc. |

No se añade una dependencia de iconos al móvil. Los controles nativos utilizan
etiquetas y los mismos marcadores de estado; no se sustituyen iconos por emojis.
Al integrar nuevos iconos nativos se mantendrá la misma familia de trazos.

Las tablas son una base de presentación; filtros, orden, paginación y carga remota
se conectarán en cada pantalla. No constituyen un segundo motor de datos.

### Uso en Oficina

Cargar únicamente en la pantalla migrada:

```blade
@vite('resources/css/estiba-ui.css')

<main class="estiba-ui" data-density="comfortable">
    <x-estiba.heading title="Discrepancias" description="Casos que requieren revisión." />
    <x-estiba.panel title="Pendientes de supervisión">
        <x-estiba.signal tone="blocked">Pausada por discrepancia</x-estiba.signal>
        <x-estiba.code>{{ $folio->numero_folio }}</x-estiba.code>
        <x-estiba.button type="submit">Revisar resolución</x-estiba.button>
    </x-estiba.panel>
</main>
```

Los botones usan `type="button"` por defecto. Se requiere `type="submit"` explícito
para enviar un formulario. `busy` deshabilita el control y comunica su estado.
La variante `critical` solo define presentación: el consumidor debe conservar
el permiso, la confirmación y la validación del servidor.

`x-estiba.field` requiere un `id` único y una `label`. Atributos de entrada como
`name`, `required`, `maxlength`, `readonly` y `type` se pasan al input. Para un
select o textarea se reutilizan las clases `eui-field`/`eui-input` y las relaciones
de etiqueta y ayuda del control nativo correspondiente.

`x-estiba.table` requiere `caption`, un slot `head` con encabezados `scope="col"`
y filas en el slot principal. Su contenedor permite desplazamiento por teclado.
No recortar códigos para hacer caber columnas; ofrecer desplazamiento o un detalle.

Las alertas son estáticas por defecto. Usar `live` solo al introducir un cambio
que deba anunciarse: errores críticos usan `alert`; avisos ordinarios, `status`.
No convertir todos los paneles de una página en regiones de anuncios simultáneos.

### Uso en tablet/PDA

Importar desde `mobile/src/components/ui/EstibaPrimitives.tsx`. Los componentes
usan siempre controles de 56 puntos como mínimo, etiquetas legibles y texto que
puede crecer. Se respeta la escala de fuente del dispositivo.

`EstibaCatalog.tsx` compone las piezas nativas con datos ficticios y estado local.
Se puede montar temporalmente en un entorno de desarrollo para revisar en un
dispositivo. No forma parte del menú productivo ni consulta APIs. Su importación
se verifica mediante TypeScript aunque todavía no se integre con una pantalla.

No escalar toda la interfaz web para que quepa en una PDA. La acción física actual
debe ser accesible sin atravesar tablas auxiliares. El orden de pasos lo suministra
la maniobra; no se reconstruye en la interfaz. Un pallet temporal sigue bajo
maniobra, sin crear posiciones ficticias. Usar siempre **Andén**.

## Densidades y accesibilidad

| Contexto | Control mínimo | Fila base | Uso |
| --- | --- | --- | --- |
| Normal | 44 px | 44 px | Oficina general. |
| Compacta | 36 px | 36 px | Tablas de escritorio con ratón/teclado. |
| Táctil | 56 px/puntos | 56 px/puntos | Tablet y PDA. |

En web `pointer: coarse` conserva el mínimo táctil aunque se seleccione compacta.
Las alturas son mínimos: aumentan si el contenido requiere varias líneas. Se
mantienen foco visible, etiquetas, estados disabled/busy, errores junto al campo y
la lectura sin depender del color. Los pares de texto de la paleta se verifican
automáticamente con contraste mínimo 4.5:1; foco y borde de input con 3:1.

## Revisar el catálogo

```bash
npm run design:check
php artisan ui:catalogo
```

Abrir `storage/app/ui/catalogo.html` en el navegador (en Git Bash, `explorer.exe
storage/app/ui/catalogo.html`). El archivo incluye CSS, SVG y JavaScript. No
requiere iniciar Apache ni importar información real. El comando regenera ese
archivo; `--output=/ruta/catalogo.html` permite otro destino.

Cada ejecución de CI conserva el HTML en el artefacto `estiba-catalogo-visual`
durante 14 días. Descargar el ZIP desde la ejecución de Actions, extraerlo y abrir
el HTML permite revisar exactamente los componentes de ese commit.

La muestra permite cambiar densidad, probar validación y ver el feedback de los
botones. Revisar escritorio 1366×768 y 1920×1080, tablet 768/1024 y PDA 390 px.
La tabla mantiene su propio desplazamiento; no debe desbordarse toda la página.

```bash
npm run design:tokens
npm run design:check
npm run test:js
npm run build
php artisan test --filter=EstibaVisualCatalogTest
cd mobile
npm run typecheck
```

## Adopción y próximos entregables

1. Fundaciones visuales: esta entrega.
2. Bandeja supervisora: integrar el contrato de consulta del PR 265 y la
   resolución del PR 264 con estas piezas.
3. Shell de Oficina: navegación/cabecera conservando dominios, rutas y permisos.
4. Control ambiental horario: capturar el registro manual que ya realiza el
   camarero; identificar cámara, operador, dispositivo y hora, y mostrar vigencia.
5. Operación ahora: leer datos reales, incluidos los controles ambientales.
6. Cámara operacional, detalle de carga y editor de planta en entregas separadas.
7. Shell, cola y ejecución de maniobras en tablet/PDA; excepciones y revisión
   final de continuidad funcional.

El control ambiental se integra antes de Operación ahora. Frecuencia inicial de
60 minutos, sin interrumpir movimientos físicos en curso y con prevención de
duplicados. El modelo de registros, asignación de avisos, correcciones auditadas y
supervisión pertenecen a ese entregable funcional, no a los componentes visuales.

Cada PR de adopción debe declarar las funciones previas y dónde siguen accesibles,
explicar qué consulta/decisión habilita cada panel y mostrar estados vacío, carga,
error y permisos aplicables. La navegación debe conservar Materia Prima,
Frigorífico, Materiales, Administración y Consultas; se reordena con conocimiento
del módulo, no copiando literalmente el menú incompleto de una maqueta.

| Funciones antes de estas fundaciones | Después |
| --- | --- |
| Navegación y apariencia de Oficina | Shell y lenguaje operacional único compartido. |
| Labores y operaciones móviles | Continúan usando su tema actual. |
| Permisos, autenticación y rutas | Sin cambio. |
| Reglas del planificador, custodia y reservas | Sin cambio; `off` no se altera. |
| Funciones eliminadas | Ninguna. |

La capa corporativa se carga al final de los estilos históricos de Oficina y
normaliza únicamente superficies bajo `.office-app`. Sus reglas de compatibilidad
pueden elevar especificidad para corregir los temas heredados, pero no alcanzan
Labores ni la aplicación móvil. No renombrar IDs usados por JavaScript durante un
cambio visual.

## Regresión visual de Oficina

`npm run ui:capturas -- <carpeta>` fotografía todas las vistas estáticas de Oficina
(rutas `Route::view`) en los temas claro y oscuro, con sesión simulada y sin ejecutar
los módulos de Vite. El resultado es determinista: dos capturas del mismo código coinciden
píxel a píxel. Requiere `npm run build` previo y Chromium de Playwright.

Para un cambio de estilos se captura antes y después, y se compara:

```bash
npm run build && npm run ui:capturas -- storage/app/ui/capturas/antes
# aplicar el cambio
npm run build && npm run ui:capturas -- storage/app/ui/capturas/despues
npm run ui:comparar -- storage/app/ui/capturas/antes storage/app/ui/capturas/despues storage/app/ui/capturas/diferencias
```

Un cambio que declara no tener efecto visual debe terminar en «0 de N capturas con
diferencias». Un cambio visual intencional adjunta las capturas afectadas para revisarlas.
La herramienta no ve filas, diálogos ni estados que dibuja JavaScript con datos del
servidor; esos se revisan con `npm run test:browser` y en el servidor de pruebas.

Solo existen dos temas de Oficina, `light-professional` (predeterminado) y
`dark-industrial`, los que acepta `normalizeOfficeTheme`. Cualquier otro valor guardado
se normaliza al predeterminado, así que la hoja no define temas adicionales.

