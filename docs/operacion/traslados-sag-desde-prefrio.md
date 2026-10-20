# Preparación SAG desde prefrío

Las inspecciones Origen y los muestreos USDA pueden incluir pallets pendientes de prefrío. El objetivo de preparación reserva su sector de inspección de nivel 1 y mantiene los espacios de separación fuera de los destinos de traslado.

Al aprobar el proceso de prefrío, cada pallet habilitado recibe una maniobra para llevarlo directamente del túnel a su posición SAG. La recepción normal de ese pallet se reemplaza si todavía es reversible. Los demás pallets del túnel mantienen su recepción habitual.

Se ocupa primero el espacio libre más profundo. Si los pallets salen de túneles distintos, el que está listo se planifica sin apartar el fondo para uno que aún espera prefrío.

El camarero toma la maniobra desde su bandeja habitual. Su destino se reserva automáticamente al asumirla y no puede cambiarse por otra posición. El registro del movimiento confirma la llegada; iniciar la inspección sigue exigiendo que todos sus pallets estén físicamente en el sector reservado.

Si una recepción ya comenzó, conserva su recorrido. El sistema vuelve a evaluar el pallet después de que termine y, si corresponde, publica el traslado desde la cámara hacia SAG. Las demás labores activas no se interrumpen.

## Recuperación

El scheduler ejecuta `php artisan sag:preparar-traslados` cada minuto. Revisa los lotes físicos en preparación de la temporada activa, por bloques de 50, y permite recuperar lotes creados antes del despliegue, falta temporal de capacidad y traslados diferidos. Un error en un lote se informa y no impide revisar los demás.

La generación requiere el planificador automático en modo guided, cálculo tablet y horizonte rolling. Se respeta el alcance de cámaras configurado.

Cancelar el lote cancela sus traslados reversibles, libera sus reservas y restaura la recepción de los pallets que siguen sin ubicación. No se permite cancelar mientras una maniobra SAG esté iniciada o conserve custodia física.

## Prueba en el servidor de pruebas

1. Confirmar worker, scheduler y arbitraje vigentes.
2. Crear una inspección Origen con dos pallets aún en el túnel y verificar que exista capacidad SAG reservada.
3. Finalizar, verificar y aprobar el prefrío. Antes de aprobar no debe aparecer el traslado SAG.
4. Revisar en la tablet las maniobras de preparación: origen túnel y destino SAG específico, con un destino distinto por pallet.
5. Tomar y completar cada maniobra. No debe aparecer otra recepción activa para el mismo pallet.
6. Iniciar la inspección después de confirmar las dos llegadas.
7. Repetir con un lote cancelado antes de mover: sus pallets deben volver a la recepción normal y su capacidad SAG debe quedar liberada.

No hay migraciones ni cambios nativos móviles en esta funcionalidad.
