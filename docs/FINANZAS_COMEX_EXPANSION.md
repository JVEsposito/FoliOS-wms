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
