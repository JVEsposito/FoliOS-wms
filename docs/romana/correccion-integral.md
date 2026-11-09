# Corrección de una recepción ya validada

Oficina → Romana → expediente → **Corregir recepción validada**. Solo para **administración**, en la **temporada activa**,
cuando Validación MP ya confirmó la recepción. Antes de eso se usa la corrección administrativa habitual de Romana.

Se envía el estado correcto completo y siempre un motivo:

- **Guías**: número y envases declarados de cada guía del camión (no se agregan ni quitan guías).
- **Conteo de Validación MP por segmento**: lo realmente recibido. La suma por tipo es lo validado de la recepción.
  Se pueden agregar tipos de envase (por ejemplo, totes registrados como bins).
- **Propiedad** por tipo de envase (cliente, propia o arrendada) en recepciones con fruta.
- **Recepción solo de envases**: el conteo recibido por tipo; la propiedad la define el concepto (compra o arriendo).
- **Destare** (recepción cerrada de fruta con envases): tara del camión, si salió con los mismos envases, con otros o
  vacío, la guía de salida y la tara unitaria de cada tipo. Con «mismos envases» la salida sigue al conteo corregido.

## Qué hace

- **Cuenta corriente y existencias**: nunca se reescribe un movimiento. Una diferencia de cantidad agrega una
  *corrección de cantidad* enlazada al ingreso (positiva o negativa) que la cuenta corriente pliega sobre la línea
  original. Un cambio de propiedad deja en cero el ingreso anterior y registra uno nuevo con la fecha original. Una
  salida corregida se revierte y se registra de nuevo con la fecha del destare.
- **Guías**: el número corregido pasa al documento de los ingresos; cada guía guarda lo validado de sus segmentos.
- **Lotes MP**: cada lote toma el conteo corregido de su segmento y los kilos se concilian con el neto corregido
  (`conciliarDestare`), igual que al corregir el peso bruto.
- **Auditoría**: evento `correccion_integral` en el expediente con estado anterior, posterior y motivo.

## Bloqueos

- Un lote con entregas a proceso no admite cambios de conteo ni de kilos.
- Una salida ya conciliada con un despacho de envases no se cambia hasta revisar esa conciliación.
- Un ingreso que ya tuvo una corrección de propiedad o de cuenta desde la cuenta corriente se corrige allí.
- En pesaje acumulativo, las cantidades y el destare vienen de las tandas: se corrigen en Romana.

## Cuenta corriente por guía

Una recepción de fruta con varias guías se muestra en el estado de cuenta (pantalla, PDF y Excel) con **una línea por
guía y tipo de envase**, con lo validado y el productor (CSG) de esa guía. Internamente sigue habiendo un movimiento por
recepción y tipo. Si lo validado por guía no cuadra con el ingreso vigente (por ejemplo, tras un ajuste manual de
cuenta), se conserva una sola línea con el resumen de guías.
