# Expansión comercial de COMEX y Finanzas

Base revisada: `main` 3cd334ce del 21 de octubre de 2026. Se amplían los maestros comerciales de COMEX y luego se incorporan acuerdos, reservas y costos auxiliares. La operación física mantiene la verdad de pallets, cargas y despachos; se reutilizan proveedores, compras, pagos y prefactura existentes.

| Dominio | Responsabilidad | Relación con otros dominios |
| --- | --- | --- |
| Operación | Inventario, instrucciones, carga, despacho | Sus UUID y temporadas identifican la evidencia física |
| COMEX | Actores, acuerdos, reservas y expediente | Lee referencias de operación y conserva copias |
| Finanzas | Valorización, compras, pagos y distribución económica | Lee COMEX con versión, fecha y copia; no cambia inventario |

Cada dominio escribe solo en su base, sin importar modelos ni crear FK hacia otra. Las proyecciones identifican `source_type`, `source_id`, `source_version`, `captured_at` y el contenido observado. Las correcciones no reescriben cierres anteriores. Una nota aprobada no reconoce ingresos por sí sola.

## Actores y lugares

Administración → Comercio exterior → Actores y lugares ofrece identidades con múltiples funciones habilitadas: operador, vendedor, exportador legal, propietario, comprador, recibidor, consignatario, notify, pagador, naviera, forwarder, agencia de aduana o transportista. Cada negocio elige expresamente quién ocupa cada función. No se deduce exportador ni propietario desde el cliente de servicios.

La identidad es país más identificación fiscal. En Chile se valida y normaliza el RUT; fuera se conserva el tax ID y no se exige dígito chileno. Código, país e identidad son permanentes; nombre, dirección, funciones y estado se modifican por versión y motivo, con actor e historial. No se precargan empresas reales ni credenciales.

El vínculo opcional con un cliente operativo es explícito, único y permanente después de confirmarlo; se verifica existencia y se conserva la copia del cliente. Nombres parecidos no crean asociaciones. Una identidad incorrecta se desactiva para nuevas operaciones y se usa la correcta; el historial permanece.

Puertos, terminales, depósitos y lugares de entrega incluyen código interno, país, localidad, dirección y código externo opcional respaldado. El código externo no implica validación oficial. Un terminal se asocia solo a un puerto activo del mismo país.

El acceso `comex.comercial` protege consultas y escrituras; los perfiles de consulta no escriben. `comex.aprobaciones` requiere además acceso comercial y habilita revisión de acuerdos. Los administradores iniciales reciben ambos. Los perfiles documentales existentes conservan su alcance. Los contactos son opcionales y este directorio no contiene datos de conductores.

Cada escritura exige UUID y huella de contenido. Reintentar devuelve la respuesta original sin duplicar eventos; reutilizar el UUID para otros datos falla. La versión impide pisar cambios concurrentes. Los maestros y obligaciones comerciales siguen disponibles entre temporadas.

## Entregas

La primera etapa comprende actores/lugares, notas de venta versionadas, bookings con asignación parcial y puente al despacho, y costos COMEX en Finanzas. Transporte terrestre con conductores/vehículos, controles tributarios del traslado, expediente regulatorio ampliado, CxC, tesorería, liquidaciones e integraciones oficiales requieren entregas específicas.

Un documento comercial interno no es DTE, DUS ni certificado. Registrar un número o adjunto no acredita validación externa. Los Incoterms se registran con lugar y versión contractual; no se infiere de ellos quién paga el flete ni el devengo contable.

Referencias verificadas al 21-10-2026: [SII exportación electrónica](https://www.sii.cl/portales/factura_expo_electronica/preguntas_frecuentes_fee.htm), [ICC Incoterms 2020](https://academy.iccwbo.org/incoterms/e-books/incoterms-2020-spanish-english/), [BIC identificación de contenedores](https://www.bic-code.org/check-digit-calculator/). El estudio suministrado por la planta orienta la secuencia; se contrasta cada entrega con el código vigente.

## Notas de venta

Administración → Comercio exterior → Notas de venta registra un folio único por vendedor, cliente de la planta, gestión, referencia del pedido, temporada de referencia y vigencia propia. La exportación propia exige que ese cliente esté configurado expresamente como propia en COMEX. La gestión de una nota existente se conserva aunque cambie la configuración global.

Cada borrador identifica operador, vendedor, exportador legal, propietario, comprador, recibidor, consignatario, pagador y contratante del flete; notify es opcional y múltiple. Las funciones deben estar habilitadas en actores activos. Una misma identidad puede ocupar varias funciones por selección expresa. Al enviar o aprobar se exige actualizar el borrador si cambió alguna identidad desde su captura.

El acuerdo contiene productos, variedad, calibre, categoría, embalaje, cantidades, kilos opcionales, unidad, precio, moneda, condición de pago, Incoterm 2020 y lugar nombrado. FOB/FAS/CFR/CIF requieren modalidad marítima. Se admiten CLP, USD, EUR, CNY, JPY y GBP, sin conversión de moneda: multiplicación decimal exacta, redondeo HALF_UP por línea y suma de importes redondeados. CLP/JPY usan cero decimales; las demás, dos. En consignación o precio abierto, un precio faltante produce total pendiente y un subtotal conocido claramente distinto. No se genera ingreso, factura ni existencia física.

El flujo es borrador → en revisión → aprobada o rechazada. Se puede retirar a borrador. La aprobación/rechazo exige acceso comercial y aprobaciones. Por defecto quien preparó la última edición no puede decidir (`COMEX_APROBADOR_DISTINTO=true`); la excepción es configurable y queda documentada aquí. Una nueva revisión conserva el acuerdo anterior hasta aprobar el reemplazo; las versiones anteriores siguen consultables. Vendedor, folio, cliente y gestión son permanentes. Cerrar exige un acuerdo aprobado y ninguna propuesta pendiente; anular conserva las revisiones y los motivos.

La impresión del navegador permite guardar PDF de la revisión seleccionada, conserva Unicode y muestra estado, revisión, responsables y el rótulo “Documento comercial interno · No tributario”. Para clientes de servicio se identifica como referencia comercial del cliente. No representa aceptación externa del comprador ni emite DTE.

## Bookings, cantidades y despacho

Book​ings y contenedores cubre reservas marítimas con naviera, forwarder, puertos, nave/viaje, fechas estimadas y cutoffs con zona horaria expresa. Código, naviera, cliente y gestión son permanentes. El borrador admite hasta 50 contenedores con referencia interna permanente, número opcional, tipo y sello. El formato del número se comprueba; no se acredita registro ni dígito BIC.

Una nota puede abastecer múltiples bookings y un booking múltiples notas del mismo cliente/gestión. Las cantidades se asignan a un contenedor y a una línea de la revisión aprobada; la suma decimal entre todas las reservas no puede superar lo acordado. El candado común serializa notas y reservas. La asignación conserva la copia del acuerdo y puede retirarse, con motivo e historial, mientras la reserva sea borrador. La salida debe quedar dentro de la vigencia contractual. No se infieren kilos desde cajas ni se reservan pallets físicos.

Confirmar requiere permiso de aprobaciones y asignaciones activas. Mientras haya asignaciones, la nota no puede sustituirse ni anularse; se retiran primero en los bookings modificables. Una nota con despachos cerrados necesita rectificación formal, fuera de este piloto, para sustituir el acuerdo. El cierre comercial de la nota exige terminar los bookings pendientes.

Un booking confirmado vincula cada contenedor a un embarque operativo existente del mismo cliente, modalidad y número de contenedor. Un embarque y un contenedor solo tienen un vínculo vigente. Se conserva la evidencia, hash y fecha de captura, y se muestra por separado el estado actual y si cambió. Retirar el vínculo conserva el historial y exige que la carga no haya salido; la falta de evidencia bloquea el retiro. El cierre exige salida operativa de todos los contenedores asignados. Volver a borrador o anular requiere retirar antes los vínculos.

Esta reserva comercial no certifica cantidades cargadas: los pallets, los instructivos y los documentos oficiales siguen en la operación y el expediente existente. El vínculo no modifica ni crea embarques, cargas, inventario o documentos tributarios.
