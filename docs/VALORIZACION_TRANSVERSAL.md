# Valorización transversal de FoliOS

Finanzas permite ofrecer servicios de cualquier área. Un concepto comercial define qué se
cobra; las medidas y reglas determinan la cantidad; el tarifario define precio, moneda,
cliente y vigencia. La operación conserva el control de sus existencias y procesos.

## Conceptos y cantidades

- **Automático directo:** toma las medidas de una fuente operativa conectada.
- **Calculado:** combina medidas de igual unidad mediante coeficientes, filtros y una
  agrupación explícita. Por ejemplo, kilos entregados menos retornos descontables del
  mismo proceso. Se genera un hecho por concepto, cliente, temporada y grupo.
- **Acordado/manual:** cualquier servicio puede tener cantidades respaldadas por ejecución,
  acuerdo, disponibilidad o compromiso, incluso cuando no se realiza la operación.

El área es un dato del catálogo, de texto libre: materiales, envases, packing, logística,
frigorífico, recepción, RRHH, COMEX u otra. No limita las posibilidades comerciales.

Las reglas calculadas se construyen desde **Tarifario → Conceptos → Nuevo concepto →
Cálculo entre medidas operativas**. Se permiten hasta ocho términos y cuatro referencias
de agrupación. Los coeficientes admiten seis decimales. Los filtros comparan valores
exactos de las dimensiones publicadas por cada fuente. No se admite SQL ni código libre.
La unidad de los términos debe coincidir; el nombre de cobro se escribe en el concepto.

Los clientes y temporadas nunca se compensan entre sí. Para fuentes diferentes se exige
una referencia común; sus identificadores individuales no se pueden equiparar. El primer
término fija la fecha del servicio. Un aporte posterior modifica la versión de ese servicio,
sin trasladarlo de mes. La opción **Exigir aportes de todos los términos** deja pendientes
los grupos incompletos. Desactivarla permite interpretar los términos ausentes como cero.

**Simular cantidad** consulta hechos ya capturados, muestra hasta veinte grupos con sus
aportes y no escribe datos. Un grupo incompleto no se cobra. Leer hechos actualiza también
las reglas dependientes; si una fuente falla, los cálculos quedan pendientes de actualizar.
Las conciliaciones conservan el historial al desaparecer o anularse una entrada.

Cada resultado conserva la regla y los identificadores, versiones, medidas y coeficientes
que lo formaron. Los conceptos son inmutables: para otra definición se crea uno nuevo.
Los cierres existentes permanecen congelados; una corrección genera una diferencia.

Un cálculo no elimina los precios de sus fuentes. Antes de ponerlo en uso se debe decidir
qué servicios son adicionales y cuáles se desean cobrar exclusivamente mediante el cálculo.

Para mantener memoria y evidencias acotadas, una regla admite hasta 10.000 grupos y cada
grupo hasta 5.000 aportes. Superar esos límites detiene la fuente con un error explícito;
se deben acotar los filtros o mejorar la agrupación, nunca truncar una cantidad a cobrar.

## Importes con signo

Los precios y las cantidades acordadas admiten signo negativo. Se conserva la multiplicación
**cantidad × precio**: un solo signo negativo produce un abono; dos producen un cargo.
La cantidad acordada debe ser distinta de cero y conserva respaldo, aprobación e historial.
La prefactura, los totales por moneda, cierres y diferencias conservan saldos negativos.
Esto no emite documentos tributarios.
