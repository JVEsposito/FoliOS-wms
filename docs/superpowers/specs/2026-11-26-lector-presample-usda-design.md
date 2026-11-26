# Lector de presample USDA en Diagramas SAG/USDA — diseño

Aprobado por el usuario el 26-11-2026.

## Para qué

La planta recibe el «Certificado de Pre-Muestreo USDA/SAG» escaneado: una página A4 con el N.º de lote y los números de las cajas a timbrar. Hoy esos datos se copian a mano al diagrama. Copiar mal una caja deja la muestra fuera de norma ante USDA.

El lector existente («Leer captura», con Tesseract) falla en este escaneo:
- leyó mal cerca de una de cada seis cajas (743 como 143, 903 como 503);
- se saltó una fila;
- no leyó los rangos de lote.

## Objetivos

- Subir el PDF de una página, o una foto en PNG o JPEG, y obtener tres datos:
  - el **N.º de lote**, que en la planta es también el número de presample;
  - el **rango de lote** (folios);
  - las **cajas a timbrar**.
- Revisar antes de aplicar. Cada caja muestra si las dos lecturas coinciden.
- Bloquear cuando el documento se contradice consigo mismo: cantidad de cajas, orden, repetidas, partida o pallets.

## No objetivos

- Armar los pallets desde los folios del rango.
- Leer exportadora, fecha, especie u otros campos del encabezado.
- Guardar el PDF.

## Anti-objetivos

- **Marcar una caja que el documento no trae.** Ninguna lectura se aplica sin la revisión y sin pulsar «Marcar».
- **Reemplazar un N.º, un Lote o un Folio** que el diagrama ya tiene con un valor distinto.

## Enfoques

1. **Solo Claude:** preciso, pero sin una segunda lectura no hay con qué confirmar cada caja.
2. **Solo Tesseract mejorado:** gratis y local, pero erra demasiado en este escaneo.
3. **Los dos:** Claude lee y Tesseract confirma cada caja. **Elegido por el usuario.**

## Cómo funciona

| Paso | Qué hace |
|---|---|
| Lectura con Claude | `POST /api/sag/diagramas/leer-presample`, con el permiso de consulta de Inspección SAG. Envía el archivo a la API de Anthropic con la clave y el modelo del asistente, y obliga a usar una herramienta. Claude devuelve: N.º de lote, tamaño de la partida, tamaño de la muestra, n.º de pallets, rangos de lote y la tabla de cajas por columna, de arriba abajo |
| Lectura con Tesseract | Lee la misma página: el PDF se convierte a PNG con `pdftoppm`. Una caja de Claude que aparece en la lectura de Tesseract queda **confirmada**; si no aparece, queda en **revisar** |
| Comprobaciones que bloquean | La cantidad de cajas es igual al tamaño de la muestra. Las cajas, leídas columna por columna, van en orden creciente sin repetirse. Ninguna pasa del tamaño de la partida. La suma de los rangos es igual al n.º de pallets |
| Costo | Cuenta en el tope mensual del asistente y queda en `llamadas_ia_asistente` con la herramienta `leer_presample`. No cuenta en el límite diario de preguntas. No exige estar en el piloto ni el modo Haiku |
| Sin clave, sin tope o con falla de la API | Responde solo con Tesseract: todas las cajas en «revisar», sin lote ni rangos, y con un aviso |
| Oficina | El panel Marcar cajas suma «Leer presample» y una revisión. «Aplicar» completa N.º, Lote y Folio solo si están vacíos, avisa cuando hay uno distinto y agrega las cajas al campo de marcas |

## Lo que queda al constructor

- El texto exacto del prompt y del esquema de la herramienta.
- El tamaño máximo del archivo: 10 MB y una sola página.
- La resolución de `pdftoppm`.
