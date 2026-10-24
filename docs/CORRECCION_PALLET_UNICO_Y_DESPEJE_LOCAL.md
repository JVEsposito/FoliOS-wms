# Pallet con un solo ejecutor y despeje en la cámara de origen

## Integración

La revisión se prepara contra `main` en `305c9a14`. Ese estado contiene #507 y #510,
pero no las correcciones de #508 y #509: esos PR se cerraron al fusionarse en ramas
intermedias (`agent/planificador-compatibilidad-envases` y
`agent/planificador-labores-coordinadas`). Estar cerrado como merged no acreditaba que
el cambio estuviera en `main`.

Este PR lleva a `main` las dos entregas ya revisadas y añade estas correcciones. Su
base es `main`; no requiere fusionar otra rama intermedia. Publicar el servidor y
actualizar la app de camareros después de integrar y aprobar CI. No agrega migraciones.

## Exclusión por pallet

La clave única de una reserva impide repetir la misma tarea, pero dos tareas de planes
distintos pueden apuntar al mismo folio. La exclusión debe comprobar el pallet completo
antes de entregar la labor, con independencia del arbitraje, del tipo de plan o de la
versión de la app.

Se bloquean los registros de folio en orden estable antes de tomar la tarea. Se leen
mediante bloqueo las tareas y reservas actuales de otros planes; no se autoriza con
el snapshot anterior de una tablet. La misma comprobación protege materialización,
inicio, renovación y registro del movimiento. Una custodia o un pallet en movimiento
sigue protegido aunque venza el lease. Los pasos futuros de una maniobra tomada
permanecen bajo su único ejecutor mientras siga vigente su compromiso.

Las labores disponibles ocultan folios ya comprometidos. «Mis tareas» filtra también
el dispositivo cuando hay un token de tablet: iniciar sesión con el mismo usuario en
otro equipo no duplica su bandeja ejecutable. La consulta histórica de recuperación
mantiene sus controles de usuario y dispositivo.

Una asignación duplicada histórica no se resuelve eligiendo arbitrariamente un dueño
ni cambiando el destino de un pallet retirado. Si los datos ya contienen dos labores
comprometidas, la nueva acción se detiene y muestra el conflicto. La custodia real debe
aclararse antes de liberar una de ellas.

## Despeje local

Para `despeje_salida_directa` y `despeje_concentracion`, la tablet selecciona primero
una posición válida de la cámara de origen. La afinidad y el puntaje eligen entre esas
posiciones; una banda de otra cámara no desplaza una alternativa local válida solo por
obtener más puntos. Las recepciones iniciales y las maniobras con destino obligatorio
conservan sus reglas particulares.

Un destino válido debe estar habilitado, libre, sin reserva de movimiento o SAG,
en una banda operativa de PT y fuera de las bandas necesarias para extraer la carga.
También debe tener acceso y soporte físico cuando corresponda. Un hueco detrás de
pallets que impiden llegar a él no constituye capacidad local utilizable. Al tomar la
labor se actualizan sus bandas protegidas a partir de las ubicaciones de la carga.

El servidor aplica la regla al materializar y antes de iniciar: rechaza un traslado a
otra cámara si existe un destino local válido y rechaza volver a bloquear una banda
de extracción. Esto protege frente a propuestas de apps antiguas. Si la cámara local
no tiene capacidad válida, sigue permitido elegir otra cámara habilitada. El movimiento
ya iniciado conserva el destino reservado.

## Verificación

- Dos tareas históricas del mismo folio y con destinos diferentes: solo un usuario
  puede tomar la labor; el otro recibe conflicto. Tras liberar correctamente la primera,
  la segunda vuelve a ser asumible.
- El mismo usuario en otra tablet no recibe como propias las labores del primer equipo.
- Un folio de un paso futuro permanece protegido por la maniobra tomada.
- Dos procesos MySQL con snapshots abiertos antes de competir por el mismo folio:
  exactamente una asignación y un conflicto, una reserva y ningún movimiento físico.
- Cámara 5 con posición local válida y Cámara 1 con más afinidad: el despeje se queda
  en la 5. Las bandas de extracción, reservas, falta de acceso y falta de soporte se
  excluyen; sin alternativa local válida puede usarse otra cámara.
- El servidor rechaza la propuesta remota aunque proceda de una app anterior y permite
  materializar la reubicación local. No se cambian destinos de movimientos iniciados.

Validación local: 131 pruebas PHP / 1.440 aserciones en MySQL; 355 pruebas
JavaScript; 96 móviles; 4 de navegador en 1280 y 390 px; TypeScript, build web,
Pint y comprobación de diff aprobados. La exportación Android/PDA corresponde al
pipeline habitual del PR.

Las pruebas de concurrencia usan datos de prueba y no acceden al servidor planta.
No se ha verificado la versión instalada de las tablets ni se han modificado sus
asignaciones activas. La identificación del incidente concreto requiere el folio y
la custodia que tiene cada operador en terreno.
