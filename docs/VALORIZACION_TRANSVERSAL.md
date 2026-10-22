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

## Procesos conectados

Además de las fuentes originales de frigorífico y romana, el catálogo ofrece:

| Área | Fuente | Medidas |
| --- | --- | --- |
| Packing | Entregas a proceso | Entregas, kilos y envases |
| Packing | Retornos regularizados por origen | Orígenes y kilos definitivos |
| Materiales | Lotes producidos de recetas | Lotes y unidades reales de salida |
| Materiales | Kardex de inventario | Movimientos y cantidades por unidad |
| Materiales | Operaciones entre almacenes | Movimientos |
| Recepción MP | Hidrocooler completado | Procesos, kilos y envases históricos |
| Logística | Lotes SAG finalizados | Inspecciones |
| Logística | Pasos del planificador completados | Pasos, con tipo de movimiento y maniobra |
| Envases | Movimientos de cuenta | Movimientos, cantidades y efectos firmados |
| COMEX | Reservas confirmadas/cerradas | Gestiones de reserva |
| RRHH | Dotación programada | Horas y jornadas de disponibilidad, sin datos personales |

Las fuentes publican dimensiones que permiten crear servicios por tipo de movimiento,
receta, versión, unidad, centro de costo, propiedad u otras referencias. El formulario
admite varios filtros por término y ofrece catálogos, incluidos recetas y versiones aunque
todavía no tengan ejecución. Si un catálogo supera 500 opciones, se puede ingresar el valor
exacto. Cada nueva fuente se registra mediante su contrato de lectura; el motor no conoce
sus tablas y la operación no depende de Finanzas.

### Kilos de packing

Elija **Cálculo entre medidas operativas**. Primer término: `Entregas a packing`, medida
`Kilos entregados`, coeficiente `1`. Segundo: `Retornos packing regularizados`, medida
`Kilos retornados definitivos`, coeficiente `-1`. Agrupe por **Proceso**. Puede filtrar el
tipo de resultado que deba descontarse conforme al servicio pactado. Cada bin multiorigen
aporta solamente sus kilos distribuidos al proceso correspondiente. Un retorno posterior
ajusta el servicio original y no origina otro cobro de procesamiento.

Los bins pendientes y retornos antiguos sin regularización no aportan kilos definitivos.
Con **Exigir todos los términos**, el proceso queda pendiente hasta contar con ambos lados.
Desactivar esa opción cobra las entregas con los retornos definitivos conocidos, sujeto a
diferencias posteriores. La definición de qué resultados se descuentan pertenece al acuerdo
comercial; el nombre del concepto debe reflejarla.

### Recetas valorizables

Para un precio por caja producida cree un concepto calculado con un término de
**Producción de recetas de materiales → Cantidad real producida**. Filtre **Receta** y
**Unidad de salida**, y agrupe por **Cada registro de origen**. Para cobrar una versión
específica, use también **Versión de receta**. El precio se asigna al concepto, por cliente
o como tarifa general. Solo los lotes cerrados aportan producción; su reversión la retira
mediante una versión financiera. La receta y la unidad de la versión utilizada se conservan.

Las cantidades de materiales de distintas unidades no son sumables. Las medidas con unidad
variable requieren un filtro explícito de unidad, tanto en conceptos directos como en
cálculos. Los conceptos sin ese filtro ofrecen lotes, folios o movimientos. Los costos de
insumos y los precios de venta del servicio permanecen independientes.

Las gestiones COMEX se fechan con la primera confirmación registrada (en registros antiguos
sin evento se conserva su creación). La dotación programada se identifica como disponibilidad;
no acredita asistencia ni genera remuneraciones. Los compromisos que deben seguir cobrándose
después de una cancelación se registran mediante cargos acordados, con aprobación económica.

## Bodegaje de materiales y servicios por período

**Bodegaje de materiales** conserva la permanencia de cada folio y reconstruye sus saldos
con el kardex. Ofrece folio-día y, con un filtro explícito de unidad, cantidad-día. El
criterio es el máximo de existencia de cada día civil en hora de planta. Un retiro parcial
no altera las cantidades de los días anteriores ni crea otra estadía. Los días con saldo
cero no se cobran. Una salida a medianoche cierra el día anterior.

El historial conserva cada movimiento y sus saldos, los tramos de existencia y los picos
de cada día. Los movimientos simultáneos se ordenan por continuidad de sus saldos. Si el
kardex no concilia con el saldo actual, la fuente falla con aviso y conserva sus versiones
anteriores; no redistribuye una diferencia sobre días pasados. Cada folio admite hasta
5.000 movimientos para esta reconstrucción.

Este servicio mide existencia bajo custodia, incluyendo material pendiente de ubicación;
un folio no implica una posición, un pallet físico o un volumen determinado. Para cobrar
capacidad reservada, posiciones o m³ contratados se usa un acuerdo por período con esa unidad.

Los **conceptos directos** también admiten filtros de dimensiones. Por ejemplo, puede crear
un concepto sobre producción de recetas filtrado por receta y unidad de salida, y asignar
un precio por caja. No requiere una expresión cuando la cantidad proviene de una sola
medida. Los reemplazos del concepto base pueden coexistir con filtros disjuntos; se rechazan
los que podrían aplicarse a los mismos hechos, fechas y días.

Para un arriendo o capacidad contratada, cree un concepto de **Servicio acordado por período**,
indique la unidad (bin, posición, m³, equipo, etc.) y asigne su precio por unidad y día. En
**Cargos acordados** registre cantidad diaria, cliente, fecha inicial, último día incluido y
respaldo. Al aprobarlo se valoriza cada día civil, incluso sin ejecución, hasta el corte de
la prefactura. Un período puede cruzar meses y cambios de hora; no se divide su duración
en bloques de 24 horas. También admite cantidades o precios negativos para abonos.

**Terminar período** adelanta el último día cobrable con motivo e historial. Conserva los
días anteriores. Si se corrige un mes cerrado, se presenta una diferencia y permanece el
cierre original. La devolución física no cancela automáticamente un acuerdo comercial:
el término económico debe registrarse. Para cambiar la cantidad diaria se termina el tramo
anterior y se aprueba otro con la nueva cantidad, evitando fechas superpuestas por decisión
comercial. Anular un acuerdo retira todo su efecto económico mediante una versión.
