# Validación PT con lenguaje de etiqueta (tablet y PDA)

Revisión de la pantalla de Validación PT de terreno (`mobile/src/screens/ValidationScreen.tsx`)
después de los PR 7 y 9. Esos PR llevaron la paleta oscura y el encabezado por área, pero la
pantalla seguía leyéndose como una plantilla genérica: siete tarjetas de color para mostrar
ceros, campos dentro de cajas dentro de cajas y el folio como un campo de texto más.

La idea rectora: **el formulario se lee como la etiqueta del pallet** y **la sesión como una
tira de talones**. Tablet y PDA comparten la misma pantalla (es adaptable), por eso los cambios
son de los dos equipos a la vez.

## Serie

| PR | Rama | Contenido |
|---|---|---|
| 1 | `diseno/validacion-etiqueta` | Lenguaje de etiqueta en tablet y PDA, piezas de `components/validation/` |
| 2 | `diseno/validacion-talon-resultado` | Talón del último resultado en lugar del aviso de texto |

## Qué no cambia

Campos, orden del formulario (bulto → origen comercial → composición → producto → categoría),
validaciones, cola sin conexión, recuperación de capturas y decisiones (aprobar, observar,
rechazar). La PDA sigue con **un solo formulario**: separar escanear y validar en pasos es un
cambio de flujo aparte (ver `pr-09-pda-validacion.md`).

## Decisiones

1. **Etiqueta.** Un solo panel con bloques numerados (franja con número y título) y celdas
   separadas por reglas: rótulo pequeño arriba, dato grande abajo. Folios, cajas, fechas, lotes
   y procesos en mono; rótulos en sans. Sin cajas dentro de cajas.
2. **Jornada plegada.** Con línea y turno elegidos, la jornada es una sola franja
   («Línea 1 · Turno A», *Cambiar*). Sin elegir, aparece el selector.
3. **Un solo indicador de sincronización** en el encabezado: «En línea · al día»,
   «En línea · N por enviar», «Sin conexión · N en la tablet/PDA». Tocarlo sincroniza.
4. **Contadores en una línea**, sin color mientras valen cero; con valor, el color y la forma
   del tono (círculo aprobado, rombo observado, cuadrado rechazo o conflicto).
5. **Talones.** Cada pallet trabajado muestra la hora en el talón, el folio en mono y el estado
   como píldora con forma. Las capturas locales llevan fondo ámbar y, si corresponde, *Retomar*.
6. **Lo accionable arriba.** Lo pendiente de una sesión anterior encabeza la columna de sesión
   en la tablet y la pantalla en la PDA.
7. **Suma neutra.** Sin cajas ingresadas la composición dice «Sin cajas ingresadas» (neutro);
   ya no aparece «✓ 0/0» en verde.
8. **Textos.** Sin mayúsculas forzadas en botones ni títulos; plural correcto («1 operación
   quedó guardada…»); horas de 24 h armadas a mano (14:05) para que no dependan de Intl.

## Piezas (`mobile/src/components/validation/ValidationLabel.tsx`)

| Pieza | Uso |
|---|---|
| `LabelSection` | Bloque numerado con franja, pista y contenido a la derecha (suma de cajas) |
| `LabelRow`, `LabelCell` | Filas y celdas con regla a la derecha (`divider`) |
| `SignalMark`, `StatusChip` | Forma y píldora de estado; el estado nunca depende solo del color |
| `LabelCounters` | Contadores de sesión en una línea (`compact` en la PDA) |
| `TalonRow` | Pallet trabajado como talón; si trae acción propia no es presionable entero |

Colores solo desde `operatorTheme` y `areas` (sin hexadecimales en los componentes).

## Capturas

Renderizadas con React Native Web a 1280 × 756 (tablet sin la franja de módulo) y 360 × 740
(PDA, en tres tramos). Datos de ejemplo.

- `capturas/validacion-etiqueta-tablet.png`, `-tablet-correccion.png`, `-tablet-sin-conexion.png`
- `capturas/validacion-etiqueta-pda.png`, `-pda-correccion.png`
