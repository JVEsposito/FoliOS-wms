# Auditoría del planificador: tres camareros y llenado de bandas

Fecha: 30 de octubre de 2026. Base: `main` en `c76c9bd65cba04f95989a42b5822aedb8adf5781`.

## Alcance

Continuación de la auditoría de cálculo central de destinos. Se prueba la exclusión por pallet, la identidad camarero/tablet, la continuidad de la labor programada, la recuperación de reservas y el aprovechamiento de las bandas. Las pruebas usan datos sintéticos y tres procesos PHP independientes conectados a MySQL, con transacciones y bloqueos reales. No se modifica inventario ni configuración de producción.

## Hallazgos y correcciones

| Hallazgo | Evidencia e impacto | Corrección |
| --- | --- | --- |
| La vecindad y la afinidad podían abrir bandas laterales antes de completar la banda de la carga. Una protección temporal se confundía con falta de capacidad. | La elección favorecía contactos laterales y bandas disponibles para otra maniobra. Eso dispersaba una carga aunque su banda tuviera espacio físico. | Se establece una banda prioritaria mientras una banda pura de la carga tenga capacidad utilizable. Se completa antes de abrir otra. Si está protegida por otra maniobra, se espera su cierre. |
| La validación del acopio competía con la protección de su propia maniobra. | La reproducción inicial de recepción rechazó el destino autorizado con «El destino no amplía el acopio vigente de la carga». | Se distingue protección propia de ajena al proyectar el sector; se conserva el destino ya reservado y se vuelve a comprobar la programación vigente de Oficina. |
| El límite de un pallet físicamente en movimiento se comprobaba por camarero y tablet. | Un mismo usuario podía iniciar trabajos diferentes desde tablets diferentes. | Mutex común por usuario y comprobación por camarero. La carrera con tres tablets admite un inicio y rechaza los otros dos. |
| La continuación de una maniobra recalculaba y bloqueaba el arbitraje de toda la temporada. | Los tres procesos reprodujeron un interbloqueo SQL al abrir una sesión. | La selección nueva se calcula antes de tomar los mutex de ejecución. La continuación comprueba su paso, propietario y reserva vigentes, sin bloquear las maniobras de otros operadores. El ciclo se persiste como snapshot con su clave única. |
| Algunas consultas `FOR UPDATE` examinaban reservas o tareas de otras cargas. | Se reprodujeron interbloqueos al iniciar movimientos de cargas independientes, incluso después de varias rondas correctas. | Se consulta por los identificadores de tarea de la carga y se utilizan los índices existentes `reservas_bloqueo_tarea_unique` y `tareas_folio_estado_idx`. Toma, materialización, inicio, renovación y liberación comparten el orden folio antes de tarea. |
| Cada renovación del lease escribía la revisión compartida de la cámara. | Pulsos que no cambiaban ocupación ni destino contendían con movimientos de otros camareros e invalidaban sus planos. | La renovación actualiza vigencia y versión de su reserva. La revisión de cámara cambia cuando cambian realmente sus reservas o su realidad física. |
| Un destino calculado por el servidor seguía figurando como recurso obligatorio tras liberar o vencer su claim. | En una repetición, la retoma fue rechazada porque otro camarero había tomado esa banda, aunque quedaban otras bandas disponibles. | Antes del primer movimiento se restaura el objetivo original y se recalcula el destino. El arbitraje también interpreta correctamente registros históricos que conservaron ese destino dinámico sin reserva. Los destinos originales inmutables siguen siendo obligatorios. |
| La cámara bloqueada y el sector de acopio podían proceder de lecturas diferentes bajo `REPEATABLE READ`. | Al pasar de la primera banda llena a la siguiente, una retoma podía ofrecer «sin destino» porque su sector seguía considerando libres bandas que otro camarero acababa de proteger. | La preparación calcula el sector con las mismas posiciones, ocupaciones y protecciones actuales que ya bloqueó para simular el destino. |
| La concentración separaba los niveles de una misma columna. | Veinte pallets en diez columnas de dos niveles se contabilizaban como un grupo de diez. | Los niveles consecutivos conectan cuando comparten banda y profundidad. No se conectan cámaras diferentes ni columnas sin contacto físico. |
| Una banda completa podía dejar de contar como concentrada cuando otra carga ocupaba bandas intermedias. | Una carga de 14 pallets en una banda llena y seis en otra podía parecer concentrada solo al 70%. | En una misma cámara se suman las bandas completamente llenas de la carga y el mayor grupo parcial conectado. No se suman entre sí grupos parciales dispersos. Oficina muestra las bandas y niveles exactos del grupo. |
| Una tarea de concentración antigua podía conservar el objetivo después de reasignar su pallet desde Oficina. | El objetivo de la tarea y la asignación actual podían referirse a cargas distintas. | La toma rechaza la concentración obsoleta y exige actualizar la labor. Una recepción sigue su asignación actual antes de comenzar el movimiento físico. |

No se reprodujo una doble toma válida de un pallet por distintos camareros: la exclusión existente por folio rechazó a los rivales. Se refuerza con carreras de tres procesos, incluyendo datos históricos que contienen tareas pendientes duplicadas y lecturas anteriores al commit del ganador. Varias tareas históricas pendientes no equivalen a varias asignaciones autorizadas: solo una puede quedar tomada con reserva vigente.

## Regla de almacenamiento

La capacidad se obtiene de las dimensiones y restricciones reales de la cámara, no de una cantidad fija de pallets. El servidor respeta la cámara objetivo vigente de Oficina, el acceso desde la entrada, el soporte físico inferior, los usos y bloqueos de banda, las reservas SAG y las protecciones de otras maniobras.

| Configuración | Carga | Distribución comprobada |
| --- | --- | --- |
| 14 posiciones por banda, un nivel | 20 pallets | 14 + 6 en dos bandas |
| Ocho posiciones por banda, un nivel | 20 pallets | 8 + 8 + 4 en tres bandas |
| 14 posiciones por banda, dos niveles utilizables | 20 pallets | Una banda, completando cada columna con soporte real |
| Banda de 14 con diez pallets de la carga ya ubicados | Diez pallets adicionales | Cuatro completan la banda inicial y seis van a la siguiente |

Una reserva entrante se considera un compromiso de espacio; no se considera soporte físico para apilar ni se cuenta como inventario recibido. Una mezcla histórica no obliga a seguir ampliándola si hay una banda pura disponible. Si no hay una banda pura posible, se conservan las reglas de seguridad y la búsqueda de capacidad del flujo existente.

El umbral de concentración continúa en 80%. No se generan movimientos adicionales por el solo hecho de que una carga correctamente almacenada use dos bandas completas no contiguas. Por ejemplo, tres bandas parciales separadas con 7 + 7 + 6 pallets siguen teniendo un grupo principal de siete; una banda completa de 14 y otra parcial de seis en la misma cámara cuentan los veinte.

## Escenarios de concurrencia

`tests/Feature/Api/PlanificadorTresCamarerosTest.php` ejecuta procesos separados mediante `tests/Support/planificador_concurrencia_worker.php`:

1. Tres tareas históricas diferentes para el mismo pallet, con lecturas anteriores a la carrera: una toma y dos conflictos; una reserva y ningún movimiento inventado.
2. Tres publicaciones simultáneas del mismo pallet: una tarea creada y dos conflictos.
3. Un camarero con tres tablets y tres tareas asumidas: un inicio físico y dos conflictos.
4. Tres camareros con funciones de turno distintas: recepción de túnel, recepción externa/packing y concentración desde otra cámara. Trabajan con tres cargas distintas que tienen el mismo cliente, marca y envase, para detectar mezclas que una afinidad comercial pudiera ocultar.

El cuarto escenario realiza 20 movimientos por operador y después ocho más por operador: 84 movimientos hasta llenar una cámara de seis bandas de 14. Mantiene tres claims en vuelo durante las rondas iniciales; en las últimas ocho por operador avanza sin barreras entre rondas. Comprueba las tareas propias por HTTP autenticado, rechaza consultas y acciones ajenas, libera y retoma tareas antes del retiro, vence y recupera leases, repite la resolución del destino y reenvía la confirmación con el mismo UUID. Durante toda la ejecución consulta repetidamente que ningún pallet tenga dos tareas con reservas activas.

Al terminar exige 84 ubicaciones y 84 movimientos, cámara de origen vacía, concentración del 100% para las tres cargas, ausencia de mezclas por banda y ninguna reserva, protección de banda, sesión o labor en curso residual. Intenta recibir un pallet adicional de una carga cuya cámara objetivo de Oficina está llena: debe rechazarlo sin crear ubicación ni dejar un claim.

`CompactacionCargaServidorTest` verifica las cuatro geometrías de la tabla, un rival durante la banda protegida y la estabilidad de la revisión de cámara durante la renovación. Las pruebas de concentración verifican conexiones verticales, bandas llenas separadas y grupos parciales que deben seguir apareciendo dispersos.

## Validación y límites

Los resultados finales de regresión se registran en la descripción del PR. Se incluyen recepción, concentración con extracciones y retornos, sesiones, exclusión concurrente, arbitraje, lecturas actuales de MySQL, turnos, despacho y fronteras físicas, además de las pruebas del cliente y su compilación.

Esta auditoría detecta fallos reproducibles y deja pruebas automáticas; no demuestra ausencia de cualquier carrera posible. Las pruebas locales usan MySQL 8.0.46 y PHP 8.3; CI usa MySQL 8.4. No simulan pérdida real de wifi, el hardware de las tablets, el volumen completo de la temporada ni latencias de red de planta. La duración por ciclo del worker incluye la espera por los otros procesos; no es una medición de latencia del servidor.

La preparación del destino todavía serializa brevemente las cámaras dirigidas para elegir con seguridad. Queda medir esa espera con las dimensiones y el volumen de producción. Esta corrección no pretende convertir ese algoritmo en un optimizador global.

## Revisión en planta

Desplegar backend y cliente compatibles, conservar `guided + rolling + server`, verificar turnos y cámaras objetivo y recalcular el arbitraje después del despliegue. La versión de destinos cambia a `servidor_maniobra_completa_v2_bandas` para invalidar snapshots de las reglas anteriores.

Antes de temporada, repetir con tres tablets una carga de 20 pallets, una banda parcialmente ocupada y una maniobra con bloqueadores. Contrastar posiciones físicas con Oficina, verificar continuidad por camarero y medir tiempos de toma y confirmación. Las maniobras históricas que ya tienen custodia requieren su flujo de resolución; este PR no borra reservas ni libera pallets físicamente retirados.
