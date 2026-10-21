# Proveedores de Finanzas

## Documentos recibidos y diario auxiliar

Proveedores es un módulo propio dentro de Finanzas, con permiso `finanzas.proveedores`. Un perfil que consulta tarifas o prefacturas no recibe acceso a costos de proveedores. Consulta puede leer; Administración puede gestionar. El perfil inicial de Administrador recibe el módulo mediante una migración aditiva.

La ficha financiera utiliza un RUT chileno canónico y único. Puede vincularse una sola vez al UUID del proveedor de Materiales. Finanzas solo lee ese maestro por `Captura/LectorOperacion`; no altera Materiales, existencias ni recepciones. También admite proveedores de servicios sin vínculo con Materiales. Los documentos conservan razón social, RUT, giro y dirección de su ingreso, aunque posteriormente se actualice la ficha. La inactivación impide nuevas compras, pero no las notas de ajuste; el bloqueo de pagos es independiente.

Esta etapa registra operaciones de una entidad contable por instalación, en CLP enteros. No convierte otras monedas, no consolida empresas ni ejecuta transferencias. Los costos no generan automáticamente cargos a clientes: su facturación sigue las condiciones comerciales, incluso acuerdos sin ejecución.

### Flujo

1. Revisar o instalar explícitamente el plan inicial. Puede crear cuentas de gasto, activo y tesorería y renombrarlas o inactivarlas. Su código y tipo quedan estables. Las cuentas de control 2101 (proveedores) y 1108 (IVA soportado por revisar) permanecen activas. La configuración de instalación puede sustituir sus códigos antes de comenzar; no deben cambiarse después de contabilizar.
2. Crear la ficha o vincular la existente en Materiales. Registrar datos tributarios, contacto y controles.
3. Ingresar un borrador con tipo, folio, fechas de emisión, recepción y vencimiento, respaldo y distribución por cuenta/CECO. La recepción se refiere al documento para controlar vencimientos, no se infiere de la descarga del camión. El plazo general se propone a 30 días desde recepción; un plazo mayor exige respaldo de un acuerdo excepcional legalmente procedente. El sistema no certifica la legalidad del acuerdo.
4. Revisar y registrar con fecha contable explícita. La distribución suma neto + exento + otros; el IVA se lleva por separado a revisión. Las cifras del documento recibido son la fuente: una diferencia frente al 19% redondeado requiere explicación. La entrada de datos no valida autenticidad, deducibilidad ni derecho a crédito fiscal.
5. Un documento contabilizado no se edita ni elimina. Corregir requiere reverso interno con motivo en un período abierto. Conserva original y asiento inverso; no anula el DTE ante el SII. Si tiene notas o facturas vinculadas vigentes, estas se revisan antes.

| Tipo | Tratamiento |
|---|---|
| 33 factura afecta / 34 factura exenta | Debe en cuentas de costo/activo e IVA por revisar; haber en proveedores. |
| 52 guía de despacho | Respaldo de entrega, sin asiento ni deuda. La factura puede referenciar varias guías. Una referencia no afirma facturación total: admite entregas facturadas por partes. |
| 56 nota de débito | Incrementa costo/activo e IVA y deuda; exige factura registrada del mismo proveedor. |
| 61 nota de crédito | Invierte costo/activo e IVA y reduce deuda; exige factura registrada del mismo proveedor. La suma de notas de crédito vigentes no excede su factura. |
| Comprobante interno de gasto | Respaldo interno sin IVA, con cuenta y CECO. No reemplaza una boleta, factura ni su registro tributario. |

El diario auxiliar conserva debe/haber, cuenta y nombre al contabilizar, CECO, usuario y fecha. Una reversión usa exactamente las líneas originales aunque una cuenta se haya desactivado. Las compras de inventario/activo fijo no se reconocen automáticamente como gasto de consumo. El diario no constituye por sí solo la contabilidad completa de la empresa, el RCV ni una declaración tributaria.

### Controles técnicos

- UUID de operación y huella canónica para reintentos de documentos; el mismo UUID con otro contenido se rechaza.
- Identidad única proveedor + tipo + folio (normaliza ceros iniciales); incluso un documento anulado conserva su identidad. El borrador admite correcciones con versión. Tras un reverso, «Crear corrección» conserva el mismo proveedor/tipo/folio en una revisión nueva vinculada a la anterior; su fecha contable no precede al reverso. No altera el original ni sus cortes históricos.
- Transacción única de Finanzas para asiento, líneas, estado y auditoría; debe = haber y solo montos positivos por lado. No usa flotantes para importes.
- Exclusión por proveedor y bloqueo de filas para notas, registros y reversos. Los períodos se bloquean en la misma transacción, preparados para cierre.
- Sin claves foráneas a bases operativas y sin emisión, aceptación, reclamo o modificación automática ante SII.

## Referencias chilenas consultadas el 21-10-2026

- [SII: Registro de Compras](https://www.sii.cl/preguntas_frecuentes/factura_electronica/001_003_6972.htm): clasificación, pendientes, reclamados y no incluir. Se conserva la separación entre ingreso administrativo y procedencia tributaria.
- [SII: guía, factura y crédito fiscal](https://www.sii.cl/preguntas_frecuentes/factura_electronica/001_003_6606.htm): la guía anterior no sustituye el impuesto recargado separadamente en factura.
- [SII: clasificación de compras](https://www.sii.cl/destacados/f29/clasificacompras.htm): activo fijo, uso común, IVA no recuperable y no incluir tienen efectos diferentes. No se considera todo IVA como crédito ni toda compra como gasto deducible.
- [Ley 19.983 vigente, artículos 2 y 3](https://www.bcn.cl/leychile/navegar?idNorma=233421): plazo general de 30 días corridos desde recepción, excepciones con requisitos y reclamo dentro de ocho días. Registrar un documento internamente no realiza aceptación/reclamo ante SII ni verifica cesiones/factoring.

Honorarios y sus retenciones, documentos de importación, cambios de sujeto, IVA de uso común, intereses automáticos, cesiones/factoring, depreciación, consumo de existencias y declaraciones requieren sus reglas específicas; no se simulan con una factura estándar. La etapa de cuentas corrientes agrega pagos registrados, aplicaciones, evaluación y reportes sin ejecutar pagos bancarios.

## Cuentas corrientes, pagos y evaluación

La pestaña Cuenta corriente puede mostrar todos los proveedores o el seleccionado, a una fecha de corte. Las deudas, notas por aplicar y anticipos están separados. La antigüedad muestra importes sin vencer, 1–30, 31–60, 61–90 y más de 90 días. Un saldo a favor de un proveedor no se aplica a otro. Las notas disponibles se muestran antes de interpretar la deuda vencida; no hay imputación automática.

### Pagos y aplicaciones

`finanzas.pagos-proveedores` es un permiso adicional al acceso a Proveedores. Solo Administración con ambos módulos registra pagos, anticipos, aplicaciones y reversos. Los perfiles de consulta no adquieren escritura por marcar el módulo. El bloqueo de pagos en la ficha requiere revisión explícita antes de registrar otro desembolso; inactivar nuevas compras no impide saldar deudas existentes.

- Registrar pago es ingresar los datos de un desembolso ya realizado, con confirmación y respaldo. No conecta al banco ni ordena transferencias. Tesorería debe identificar sus cuentas reales en el plan.
- El pago carga el activo de anticipos/pagos por aplicar (1205 por defecto) y abona tesorería. Una aplicación a factura carga proveedores y abona anticipos. Puede aplicar una parte al crear el pago y distribuir el resto posteriormente, sin reconocer un gasto de nuevo.
- La nota de crédito ya reduce proveedores al contabilizarla. Su aplicación solo vincula el ajuste a la factura que referencia y no repite el asiento.
- La referencia del comprobante, normalizada, es única entre pagos vigentes de la misma cuenta de tesorería. UUID y huella impiden duplicar solicitudes y rechazan la reutilización del identificador con otros datos.
- No se aplican importes superiores al saldo del crédito ni de la deuda. Se serializa por proveedor y se verifica dentro de la transacción, incluyendo aplicaciones iniciales: si una falla, no queda pago ni asiento parcial.
- Para revertir un pago, factura o nota aplicada se desaplican primero sus abonos. El reverso conserva su asiento original y crea el contrario. Una referencia de pago puede corregirse después de revertir su ingreso anterior, con fecha posterior o igual al reverso.
- Una nueva aplicación no precede a la deuda, al crédito ni a sus últimas desaplicaciones. La corrección de una factura no precede al reverso anterior. Estos límites evitan reutilizar un importe en una fecha en que todavía estaba ocupado.
- Los cortes anteriores conservan las aplicaciones y documentos que estaban vigentes en esa fecha, aunque se hayan revertido después. Un anticipo no se presenta como gasto ni como pasivo negativo: se muestra como activo disponible y se informa también el saldo neto económico.

La consulta filtra en SQL las partidas saldadas antes de cargar resultados; un historial extenso de facturas pagadas no consume el límite. Si una consulta supera 5.000 partidas pendientes por tipo, pide acotar por proveedor y no muestra totales parciales. Los comprobantes y aplicaciones históricos tienen paginación.

### Contabilidad y cierres

Balance y gastos consulta el diario auxiliar con saldo inicial, debe, haber y saldo final por cuenta. Incluye mayor con saldo acumulado, reporte de gastos netos por cuenta y CECO y exportación CSV. El CSV protege los textos que podrían interpretarse como fórmulas. Las compras clasificadas como activo no se convierten en gasto por pagarlas. Los nombres de cuenta en el balance son los actuales; los asientos conservan sus nombres históricos.

Los períodos se cierran explícitamente con motivo. Si hay borradores emitidos en el mes, deben revisarse primero. El cierre bloquea nuevos asientos, aplicaciones y reversos en ese mes, compartiendo el bloqueo de la fila del período con las escrituras. Se puede usar un período posterior abierto para corregir, o reabrir con historial el último mes cerrado. El cierre de compras es independiente de la prefactura a clientes; no sustituye conciliación bancaria ni validación tributaria.

### Evaluación

Cada evaluación conserva período, evaluador, criterios, pesos, notas de 1 a 5, conclusión y respaldo. Los pesos deben sumar 100%; la nota final se calcula con aritmética decimal exacta. Puede agregar criterios propios. La evaluación es inmutable; una equivocación se anula con motivo y se registra otra. La conclusión es una decisión explícita y no bloquea automáticamente pagos ni altera Materiales.

Esta etapa no incorpora devoluciones bancarias de anticipos/saldos a favor, documentos en moneda extranjera, reconocimiento de diferencia de cambio, compensaciones entre clientes/proveedores, conciliación bancaria, declaraciones SII ni libros oficiales. Los saldos a favor permanecen identificados hasta gestionar su aplicación o un flujo específico de devolución. El diario auxiliar alimenta una futura integración contable; no se presenta como una contabilidad completa de toda la empresa.
