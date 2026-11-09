# Recepción rolling desde Prefrío

## Objetivo

Cuando un proceso de Prefrío es aprobado, el WMS puede convertir automáticamente los pallets que requieren almacenamiento en un objetivo operacional `recepcion_tunel`.

El objetivo no preasigna cámara ni posición. Cada pallet se incorpora como una tarea `ubicacion_inicial` con horizonte `rolling`; la bandeja de tablet de PR #250 calcula una frontera corta y el servidor arbitra/reserva el destino físico.

## Activación

La generación conserva rollback explícito y solo se habilita cuando el flujo
rolling puede resolverse de extremo a extremo:

```env
WMS_PLANIFICADOR_AUTOMATICO=true
WMS_PLANNER_MODE=guided
WMS_PLANNER_COMPUTE=tablet
```

Con `WMS_PLANIFICADOR_AUTOMATICO=false`, o si el planificador no está en
`guided + tablet`, aprobar Prefrío mantiene el comportamiento histórico y no
crea planes. Esta condición evita dejar tareas abstractas que el servidor no
permitiría materializar.

Con la bandera activada, los objetivos generados por este flujo fijan en su propio contexto:

```text
planner_horizon = rolling
```

El comportamiento no depende del horizonte global configurado para otros planes.

## Universo del objetivo

Solo se materializan tareas para asignaciones del proceso que estén aprobadas y cuyo folio:

- sea un pallet completo activo;
- todavía no posea ubicación actual;
- no tenga una asignación vigente a una carga `directa_prefrio`.

Saldos/REPA siguen fuera del planificador. La salida directa de Prefrío continúa bajo el flujo de carga existente y no compite por una posición de cámara.

## Origen y destino

El túnel se conserva como origen lógico en el contexto de la tarea:

- proceso de Prefrío;
- túnel;
- posición dentro del túnel;
- marca, formato, exportadora, variedad y calibre disponibles.

La tarea se crea sin `camara_destino_id` ni `posicion_destino_id`. El destino exacto aparece después, cuando la frontera rolling es materializada por el servidor.

## Saldos y pallets ya estibados

El planificador **nunca pide estibar saldos** y **no repite una salida de túnel de un pallet que ya está en
cámara**. `ServicioDepuracionIngresosCamara` suelta los ingresos a cámara (planes `recepcion_tunel` y
`almacenamiento_pallet`) que aún no empiezan —bloqueados, pendientes o tomados con destino reservado— cuando:

- el folio es tipo **Saldo** o ya no está activo, sin importar cómo nació la tarea: una repa que deja el pallet como
  saldo o lo consume, una corrección a saldo en Validación o una salida restaurada al sacar el pallet de una carga
  de despacho directo (que ahora tampoco se restaura si hoy es saldo);
- el folio ya tiene una posición en cámara, por ejemplo porque el camarero lo estibó por su cuenta. La salida se
  da por cumplida; si quedó fuera de afinidad, lo corrige el reordenamiento, no otra salida de túnel. Si el pallet
  tiene otra labor activa que lo saca de su posición (retiro, despeje), su ubicación es transitoria y no se toca.

Se ejecuta al instante al estibar sin la tarea, al corregir a saldo y al registrar la repa, y en cada minuto como barrido antes de
`planificador:recalcular-arbitraje`. La tarea queda cancelada con el motivo y, si tenía maniobra, la maniobra se
cancela de forma reversible y libera reservas y bandas. Un paso en ejecución (pallet en la grúa) se respeta y se
reevalúa en el barrido siguiente.

### Discrepancias de una salida que ya no corresponde

Si el camarero reporta una discrepancia en una salida de túnel cuyo pallet ya está ubicado (o es saldo), Oficina
muestra «Corresponde cancelar sin reemplazo» con el motivo, deshabilita **Reanudar** (volvería a pedir el
movimiento en la PDA y quedaría en bucle) y habilita **Cancelar sin reemplazo** aunque el paso figure «en
movimiento». El barrido hace lo mismo solo y deja la discrepancia resuelta automáticamente con el motivo. Si en la
PDA se intenta ubicar un pallet que ya está ubicado, el mensaje indica dónde está y que no hay que moverlo.

## Idempotencia

Cada proceso de Prefrío puede originar como máximo un plan mediante la referencia estable:

```text
referencia_tipo = proceso_prefrio
referencia_id   = <uuid del proceso>
```

La base de datos protege la combinación con un índice único. Un retry de aprobación o una carrera concurrente recupera el plan ya creado en lugar de duplicar el objetivo.

## Condición de término

`recepcion_tunel` posee una condición de término específica: el plan se completa únicamente cuando todas sus tareas planificadas están `completada`.

Una tarea cancelada no se interpreta como pallet recibido. En ese caso el plan permanece abierto para hacer visible la anomalía y permitir una resolución posterior.

## Límites de PR #251

Este incremento no incorpora todavía:

- presencia del camión en andén;
- replanificación desde cámara hacia despacho directo;
- concentración de cargas;
- reservas SAG;
- retenciones automáticas;
- movimientos de oportunidad;
- evacuación de cámaras.

La llegada de camión y la prioridad de salida directa corresponden al siguiente incremento del programa.
