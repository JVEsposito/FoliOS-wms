# Corrección administrativa del peso bruto

En **Romana**, abrir la recepción y seleccionar **Corregir peso bruto**.
La acción está disponible exclusivamente para un administrador activo con acceso
a Romana. Los demás usuarios pueden registrar el peso inicial, pero no modificar
un bruto ya guardado mediante la edición del ingreso ni la nueva API.

1. Revisar el bruto y la tara registrados. En pesaje acumulativo, seleccionar la
   tanda que contiene la lectura equivocada; no se sobrescribe el total acumulado.
2. Ingresar el bruto correcto, con hasta tres decimales, y revisar el neto resultante.
3. Explicar el motivo (10 a 1.000 caracteres) y guardar.

La corrección conserva la tara, las cantidades, el cliente, los documentos y el
estado del expediente. Si el camión aún no ha sido destarado, el neto queda
pendiente. Si ya cerró, se recalculan neto, neto por envase y reparto utilizando
las cantidades y referencias congeladas. En una tanda mixta se mantienen las
proporciones de neto medidas originalmente, con ajuste exacto al gramo.

El historial registra administrador, fecha, motivo, bruto anterior y nuevo, los
netos resultantes y, cuando aplica, la tanda y su desglose. El RPR-01 se genera
con los valores corregidos. No se vuelven a registrar entradas o salidas de
envases ni movimientos de cuenta corriente.

Los lotes de MP **en borrador** se concilian en la misma transacción. Si hay lotes
confirmados, el cambio se rechaza: sus kilos deben conciliarse antes, porque ya
pueden formar parte de inventario o entregas. No se permite corregir recepciones
anuladas o eliminadas, temporadas inactivas ni recepciones de solo envases, que
no tienen pesaje. Una tanda anulada o de otra recepción tampoco puede editarse.

Si el expediente cambió mientras se completaba el formulario, actualizarlo y
abrir de nuevo la corrección. Un reintento del mismo envío conserva su UUID y no
duplica el evento ni el recálculo. No requiere migraciones.
