# Consumos por orden de trabajo · Materiales 14/15

## Operación

La orden de transformación existente es la OT. Al crearla desde Oficina se puede
declarar referencia de producción, proceso y operador físico. Fecha operacional,
línea, turno, bodega y versión de receta mantienen sus reglas. Los clientes
instalados pueden omitir los campos nuevos. No se rellenan responsables ni
procesos históricos con datos supuestos.

**Materiales → Consumos por OT** ofrece comparación por componente y un detalle
con los movimientos de origen y el usuario autenticado que los registró. Los
filtros usan la fecha operacional, inclusiva, sin convertirla a una fecha UTC.
Los horarios de movimientos se muestran en Chile. La consulta histórica y el
Excel no requieren que esa temporada siga activa ni crean registros.

El operador físico es una declaración de Oficina; no sustituye la identidad del
usuario autenticado ni acredita que esa persona haya iniciado sesión. Si no se
declaró, se muestra «Sin declarar». En los movimientos de transformación se
muestra el operador declarado de la OT; en consumos compartidos se conserva el
operador declarado al registrar ese consumo, o el de cada OT si se omitió.

## Comparación y fuentes

- Estándar planificado = componente estándar × salida planificada / base de receta.
- Estándar para salida real = componente estándar × salida de lotes cerrados / base.
- Consumo neto = retirado para transformación − devuelto/reversado + compartido imputado.
- Utilizado = consumo neto − merma declarada de cuadratura − merma compartida.
- Desviación neta = consumo neto − estándar para salida real; incluye la merma.
- Desviación de utilizado = utilizado − estándar para salida real. Porcentaje solo si el estándar
  es positivo; un estándar cero no produce división por cero.

Se utiliza el snapshot de la receta de la orden, no su versión actual. Los lotes
anulados no aportan producción. El ledger `movimientos_inventario_materiales`
aporta retiros, devoluciones y las devoluciones positivas de reversa; no se suma
además la tabla de consumos de lotes ni el espejo de almacenes. Los movimientos
de producción y sus reversas negativas no son consumo de componentes.

En `cuadratura_cierre`, lo retirado todavía no equivale a lo utilizado: mientras
exista un retiro neto y la orden no esté cerrada/cancelada, utilizado y desviación
permanecen pendientes. Al cerrar se utiliza la merma efectivamente declarada.
Una cancelación que devuelve todo deja consumo neto cero.

En modo por lote, el ledger representa consumo real sin desglose de merma por
componente; no se inventa ese dato. La merma de producción se presenta por
separado en la unidad de salida. No se suman cantidades de unidades distintas.
Un insumo no incluido en la receta muestra estándar y desviación vacíos y
evaluación `sin_estandar`.

## Insumos compartidos

Se amplía el comando existente `POST /api/materiales/almacenes/movimientos`:

```json
{
  "tipo": "consumo",
  "operador_nombre": "Persona en línea",
  "imputaciones_ot": [
    {"orden_id": "UUID-OT-1", "cantidad": 3.5, "cantidad_merma": 0.5},
    {"orden_id": "UUID-OT-2", "cantidad": 6.5, "cantidad_merma": 0}
  ]
}
```

Se conservan los campos obligatorios del comando: UUID de operación, folio,
bodega, centro de costo cuando corresponde, cantidad física, motivo y excepción
FIFO si hace falta. El ejemplo reparte un consumo físico de 10; no lo consume
dos veces. La merma está incluida en cada cantidad, nunca es un descuento extra.

Solo admite insumos y hasta 50 órdenes distintas en `en_proceso` o
`pendiente_cierre`, de la temporada activa y del mismo cliente propietario del
insumo. Requiere tanto autorización del consumo de almacén como
`gestionar-transformaciones-materiales`. Las sumas se comparan en milésimas.
Las órdenes se bloquean por ID antes del folio y sus saldos, compatibles con el
orden de bloqueo de transformación. Una orden cerrada, exceso de reserva/saldo,
merma inválida o distribución incompleta revierte toda la operación.

Un solo movimiento físico conserva los saldos y la imputación al centro de costo
existentes. Las filas de `imputaciones_consumo_materiales` distribuyen ese
movimiento; son inmutables y guardan cantidades, unidad, ítem, contexto y
registrador originales. Repetir el UUID con las mismas líneas, incluso
reordenadas, devuelve el movimiento original; cambiar cantidades responde 409.
No se permite editar una imputación confirmada ni usarla para representar
material prestado/sin consumir. Las devoluciones de transformación conservan su
circuito existente; no se genera una devolución ficticia de un consumo definitivo.

## Consultas y permisos

- `GET /api/materiales/transformaciones/consumos`: órdenes paginadas y componentes.
- `GET /api/materiales/transformaciones/ordenes/{orden}/consumos`: detalle y actores.
- `GET /api/materiales/transformaciones/consumos/excel`: todas las órdenes filtradas,
  recorridas en bloques de 100, una fila por componente/unidad. Los listados
  y el Excel agregan retiros/devoluciones y producción en SQL, sin cargar cada
  movimiento/lote de todas las órdenes; los actores se cargan al pedir un detalle.
- `GET /api/materiales/transformaciones/consumos/ordenes?folio_id=...&q=...`:
  búsqueda de OT para distribuir, con hasta 50 coincidencias e indicación para
  acotar si hay más. Requiere también gestión de transformaciones.

La lectura reutiliza `consultar-transformaciones-materiales`; las consultas no
modifican inventario ni disparan recálculos. Bodega del filtro representa la
bodega de componentes de la OT; un insumo compartido conserva su propio origen
en el movimiento físico.

## Despliegue

Aplicar `2026_10_25_120000_agregar_contexto_y_consumos_por_ot_materiales`, compilar
Oficina y reiniciar workers con el procedimiento habitual. No cambian dependencias,
runtime móvil, variables de configuración ni APK. Este PR no requiere OTA.
El panel global ampliado del plan corresponde a la etapa 15.

Prueba funcional: crear una OT con contexto, ejecutar sus lotes o cuadratura,
comprobar estándar/real y devolución, y contrastar el registro con el kardex.
Distribuir un consumo de insumos entre dos OT en ejecución; verificar un único
descuento, sus actores, una distribución rechazada sin descuento y el Excel de
una temporada histórica.
