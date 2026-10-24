# Capacidad de bodegas — PR 10 del plan de Materiales

**Materiales → Capacidad de bodegas** (`/oficina/materiales/capacidad`).
Configura los almacenes físicos existentes en `destinos_materiales`. Los centros
de costo virtuales no representan recintos físicos. Las altas y la operación de varias bodegas corresponden al PR 11. El
[PR 12](materiales-asignacion-bodegas.md) configura la bodega de ingreso de
recepciones; la ubicación física y capacidad libre siguen requiriendo verificación.

## Datos configurables

- Tipos de pallet: código, nombre, largo, ancho, altura total cargada, máximo de
  niveles apilados, peso máximo opcional y estado. No se presuponen dimensiones
  estándar de industria ni alturas de carga.
- Almacenes: largo, ancho y altura útil máxima. Zonas rectangulares útiles con
  origen X/Y, dimensiones, altura, tipos de pallet habilitados, permiso de giro,
  límite de pallets y peso máximo opcionales.
- Zonas a piso: referencia de retícula uniforme, con el menor límite de apilado
  entre la zona, el tipo de pallet, la carga y su altura disponible.
- Racks: número de huecos físicos declarado por el responsable y medidas útiles
  de cada hueco. Un pallet por hueco. No se deducen niveles de rack a partir de la
  resistencia al apilado del pallet. El conjunto declarado no puede superar el
  volumen de la zona; el conteo de huecos requiere comprobación en terreno.

Todas las entradas se expresan en metros, con precisión de milímetros. La
geometría se guarda y calcula en milímetros enteros. Las zonas deben quedar
dentro del almacén y no superponerse. Los pasillos se representan dejando esos
espacios fuera de las zonas útiles.

## Medidas reales y simulación

Las medidas reales se guardan por saldo físico de un folio en un almacén, junto
con el número explícito de unidades físicas, peso unitario opcional, responsable,
fecha, cantidad de referencia y versión del saldo. Cambiar el catálogo de pallets
no reescribe esas medidas. No se deduce el número de pallets desde cajas o kilos.

Si cambia la versión del saldo, incluso por una reserva, la medición queda
pendiente de verificar. Esto también detecta cambios que devuelven la cantidad
al valor anterior. La escritura verifica la versión conocida del saldo y de la
medición. Solo admite folios activos con saldo positivo en almacenes físicos de
la temporada activa.

La simulación prueba un formato por zona y devuelve puestos/huecos, apilado,
orientación y límites. Una carga que excede largo/ancho/altura o peso es
incompatible aunque sobre volumen. Si hay un límite de peso y falta el peso de
la carga, la zona queda pendiente y no se suma al total verificado.

El resultado es capacidad de referencia: no resta ocupación ni reservas y no
optimiza mezclas de orientaciones, formatos o clientes. No constituye una
asignación física. Las lecturas y simulaciones no generan movimientos.

## Permisos, conservación y despliegue

La consulta reutiliza el permiso de kardex (`materiales.inventario`). Escrituras
separadas: `capacidad.configurar` y `capacidad.medir`. Los perfiles anteriores
sin selección explícita no reciben estas nuevas escrituras; el Administrador
protegido conserva administración completa. Un Administrador sin perfil asignado
puede configurar y medir; un Supervisor de Materiales sin perfil puede medir.
Los perfiles personalizados pueden delegar cada acción por separado.

Una migración agrega cuatro tablas: tipos, configuraciones, mediciones y auditoría.
Las escrituras registran antes/después, actor, UUID de operación y versión. Un
reintento con igual UUID y datos devuelve el resultado previo; reutilizarlo con
otros datos o usuario se rechaza. El historial se consulta paginado. Al eliminar
una recepción administrable, sus mediciones se eliminan en cascada con el saldo
y se conserva la auditoría independiente. La función no cambia cantidades,
reservas, folios ni ubicación. La interfaz de Oficina no requiere una APK nueva.
