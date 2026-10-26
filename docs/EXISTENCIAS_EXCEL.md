# Existencias en Excel

## Alcance

La oficina `/oficina/existencias` entrega tres inventarios independientes y un historial de despachos:

1. **Producto terminado:** una fila por línea de composición del folio activo de pallet o saldo. Cada línea conserva sus cajas y atributos reales.
2. **Materiales:** una fila por folio material, conservando cantidad inicial, actual, reservada, disponible y unidad de medida.
3. **Materia prima:** una fila por lote vigente, con trazabilidad, pesos, hidrocooler y cámara asignada.
4. **Despachos de producto terminado:** una fila por folio despachado en cargas cerradas, filtrable por cliente y período (ver más abajo).

El servidor define qué registros constituyen existencia. Excel no reconstruye estados ni suma unidades incompatibles.

## Modalidades

### Corte estático XLSX

Genera un libro en formato de base de datos, listo para filtrar, cruzar o cargar en otro
sistema:

- hoja de datos con los **encabezados en la fila 1** y una fila por registro, sin títulos
  ni celdas combinadas;
- encabezados congelados y autofiltros;
- valores numéricos y fechas almacenados como tales;
- hoja **Corte** con reporte, fecha y hora de corte, usuario, temporada, cantidad de
  registros y, si se aplicaron, cliente y período.

El archivo no cambia después de descargarlo y sirve como evidencia histórica.

### Consulta conectada para Excel

Genera un archivo `.iqy` que contiene una URL de consulta de solo lectura. Al abrirlo, Excel descarga la existencia vigente desde el WMS.

Flujo recomendado para el usuario:

1. abrir el `.iqy` con Excel;
2. aceptar la conexión externa;
3. guardar el libro como `.xlsx`;
4. usar **Datos → Actualizar todo**;
5. en las propiedades de la conexión, activar **Actualizar al abrir el archivo** cuando corresponda.

No requiere instalar un controlador ODBC ni entregar credenciales de MySQL al usuario final.

## Seguridad

Cada conexión:

- pertenece a un único usuario;
- está limitada a una sola existencia;
- conserva los permisos actuales del usuario;
- vence después de un año;
- puede revocarse desde la oficina de Existencias;
- deja de funcionar inmediatamente si el usuario se desactiva o pierde el permiso correspondiente.

El token se guarda cifrado mediante hash SHA-256; el servidor no conserva el token en texto plano.

## Permisos

- Producto terminado: perfiles autorizados para producto, cargas, Prefrío o consulta gerencial.
- Despachos de producto terminado: perfiles con consulta de cargas.
- Materiales: perfiles con consulta de inventario y despachos de materiales.
- Materia prima: perfiles con consulta de lotes de materia prima.

La API vuelve a comprobar el permiso en cada actualización del archivo conectado.

## Rutas

- `GET /api/existencias`
- `GET /api/existencias/{tipo}/corte`
- `POST /api/existencias/{tipo}/conexion-excel`
- `GET /api/existencias/{tipo}/consulta?token=...`
- `POST /api/existencias/conexiones/{conexion}/revocar`

Tipos válidos:

- `producto-terminado`
- `materiales`
- `materia-prima`
- `despachos-producto-terminado`

## Despachos de producto terminado por cliente

La oficina **Frigorífico → Existencias PT** agrupa dos archivos:

- **Existencia de producto terminado**: folios activos de la temporada.
- **Despachos de producto terminado**: una fila por folio despachado en cargas cerradas,
  con fecha de salida, carga, orden de embarque, patente, conductor, cliente, producto,
  CSG, fecha de embalaje, lotes de materia prima y procesos de packing.

La barra de filtros permite elegir un **cliente** (aplica a ambos archivos) y un
**período de salida** (solo despachos). Así se genera un archivo por cliente para enviarlo
sin exponer información de otros clientes:

```http
GET /api/existencias/despachos-producto-terminado/corte?cliente=Exportadora%20Norte&desde=2026-09-01&hasta=2026-09-30
```

El nombre del archivo incluye el cliente. Las conexiones autoactualizables (`.iqy`)
entregan la temporada completa sin filtro de cliente.

## Desglose de pallets y saldos MIX

La consulta autoactualizable y el corte XLSX usan la composición vigente del folio,
en lugar de repetir su resumen MIX. Cada línea conserva su cantidad de cajas, cliente,
marca, especie, variedad, calibre, envase, categoría, CSG y predio. Ubicación, carga y
estado SAG continúan correspondiendo al bulto físico.

Las columnas existentes conservan su orden. Al final se agregan fecha de embalaje,
lote de materia prima, proceso de packing y estado del desglose. Los atributos guardados
en la composición tienen prioridad; las referencias de validación se resuelven en
bloques de 200 folios, sin consultas por línea, y la genealogía solo aporta datos
cuando el origen coincide y su valor es inequívoco.

Si una agrupación histórica conserva sus aportes originales, estos se recuperan
como filas separadas solo cuando coinciden sin ambigüedad y suman exactamente
las cajas de cada componente actual. Los aportes originales no se reparten
proporcionalmente sobre un saldo parcial.

Un mismo folio puede ocupar varias filas. Para cuadrar cajas se suma «Cantidad de
cajas»; para contar pallets o saldos se usa el recuento distinto de «Folio».

No se distribuyen cantidades por suposición. Si faltan cajas en la composición,
se muestra una fila con las cajas restantes y el estado «Cajas sin composición
registrada». Si la composición excede el total o contiene cantidades inválidas,
se conserva el total del folio y se señala la inconsistencia. Un atributo MIX sin
detalle recuperable queda identificado como desglose parcial.

Las conexiones IQY vigentes conservan la misma URL y token. Después de desplegar
el servidor, «Datos → Actualizar todo» obtiene el desglose sin crear otra conexión.
No requiere migraciones ni cambios en la app de camareros.
