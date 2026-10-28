# Anulación de recepciones y saldos anteriores de envases

Solo el administrador con acceso al módulo puede ejecutar estas acciones. No se corrigen datos automáticamente al desplegar.

## Anular recepción

En Romana, abrir el expediente y elegir **Anular recepción**. Confirmar la recepción, guía y cliente; escribir el motivo. La recepción sale del listado vigente y de los pendientes de Validación MP. El filtro **Anuladas** permite consultar el original, usuario, fecha y motivo. Su aviso de recibo queda marcado como ANULADA.

Si la recepción ya confirmó ingresos de envases y no tiene dependencias, se registran reversas de cuenta y existencia vinculadas a los originales. Si está en validación, tiene segmentos o lotes, guías, reservas, correcciones o diferencias de existencia, la solicitud explica el bloqueo y no hace cambios. El número de recepción y la guía mantienen su trazabilidad y no se reutilizan.

La misma operación reenviada con los mismos datos devuelve el resultado anterior. Un nuevo intento sobre una recepción ya anulada no genera otra reversa. El motivo es obligatorio y se valida nuevamente en el servidor.

## Corregir saldo anterior

En **Cuenta Envases → Movimientos**, abrir las acciones del ingreso en arriendo, apertura o ajuste y elegir **Corregir saldo anterior**. Esta acción se utiliza exclusivamente para una obligación histórica, no para registrar una entrega física. Elegir quién debe envases, ingresar la cantidad correcta, confirmar que es saldo anterior e indicar el motivo.

La pantalla muestra el importe anterior y el correcto, la diferencia y el saldo total según la cuenta consultada. El servidor valida el importe conocido para impedir que dos pantallas apliquen dos veces la misma corrección. Si el dato cambió, exige actualizar.

Ejemplo comunicado: un cliente debía 138 bins, registrados como +138. Seleccionar **El cliente debe envases (−)** y cantidad **138**. La diferencia que registra el sistema es **−276**, con efecto **0 en existencia**. Con otras entregas de 444 bins, el saldo total pasa de −306 a −582. No ingresar −276 como cantidad correcta: esa es la diferencia calculada, no la obligación final del asiento.

El kardex conserva el ingreso original y la corrección con motivo, usuario y fecha. El estado de cuenta PDF/Excel agrupa la corrección con el asiento original y lo presenta como **Saldo anterior corregido**, con su signo correcto. Los movimientos de préstamo interno de bins de planta siguen con su comportamiento anterior.

Esta corrección no transforma un ingreso físico en apertura ni cambia su existencia histórica; resuelve únicamente el importe de la cuenta. Si también se digitó una recepción física inexistente, revisar ese registro por el circuito de anulación y conciliación, evitando sumar ambas acciones sobre un mismo error.

## Despliegue

Estos cambios son del servidor y la web de Oficina. Compilar los recursos con `npm ci` y `npm run build`, limpiar cachés y reiniciar workers. No requieren otra OTA, APK ni migraciones adicionales. Al integrar desde una versión anterior, ejecutar las migraciones pendientes del resto de main, incluido el préstamo interno del PR #531.
