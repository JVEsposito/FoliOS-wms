# Conductores y transporte reutilizable

PR 4 del plan de Materiales. Primera integración en Oficina → Materiales → Recepciones. El directorio es global: una persona, empresa o vehículo puede trabajar con distintos clientes y continuar entre temporadas. No supone una relación permanente entre conductor, camión y transportista.

## Operación

1. Escribir el RUT del conductor y salir del campo, o pulsar **Buscar conductor**. Recupera nombre y teléfono. Si hay un viaje previo, **Usar transporte del último viaje** permite traer camión, carro y empresa; hay que revisar que correspondan al ingreso actual.
2. La empresa se busca por su propio RUT y el vehículo por patente. Es posible cambiar camión, carro y empresa en cada recepción.
3. Al guardar la recepción se crean las fichas nuevas. Una empresa sin RUT queda descrita en el viaje: no se fusionan empresas por nombre.
4. Los cambios de datos existentes se aplican al viaje. Para reemplazar también la ficha reutilizable hay que marcar **Actualizar los datos guardados…**. Si otro usuario actualizó la ficha desde la búsqueda, el sistema pide buscar nuevamente y revierte la operación completa.

RUT con/sin puntos, espacios o guion y patentes con separadores se normalizan antes de buscar y guardar. Se comprueba el dígito verificador del RUT. Las claves únicas impiden duplicar identidades. Buscar no crea ni modifica registros.

## Historial y consistencia

Cada recepción conserva `transporte_snapshot`: datos del conductor, empresa, camión y carro utilizados en ese viaje, más identificadores y versiones del directorio cuando corresponden. Las vistas y la confirmación usan esa copia; no consultan el maestro para reconstruir antecedentes históricos.

Actualizar una ficha no modifica recepciones anteriores. Editar solamente una observación conserva el transporte y su versión histórica. Las correcciones de transporte dejan copia anterior/resultante en el evento de la recepción. Las altas y actualizaciones del directorio registran antes/después, operación, ID y nombre del responsable. Todo se confirma o revierte dentro de la transacción de recepción; un error de cantidades no deja fichas nuevas huérfanas. Los reintentos conservan la idempotencia de recepción.

El conductor y el transporte siguen siendo opcionales. Los clientes anteriores pueden enviar solamente `patente` y `transportista`. No se reescriben ni se intentan identificar textos de recepciones antiguas durante la migración. Cuando un cliente anterior corrige camión o transportista de un viaje con snapshot, se conserva al conductor y se desvincula únicamente la identidad que cambió.

## API y acceso

Consultas con el permiso existente `consultar-recepciones-materiales`, con respuesta privada sin caché:

| Consulta | Resultado |
| --- | --- |
| `GET /api/materiales/transporte/conductor?rut=…` | Ficha o `null`, y transporte del último viaje no anulado |
| `GET /api/materiales/transporte/empresa?rut=…` | Empresa o `null` |
| `GET /api/materiales/transporte/vehiculo?patente=…` | Vehículo o `null` |

Las escrituras ocurren únicamente al guardar o corregir una recepción, respetando sus permisos y controles de temporada existentes. `POST/PUT /api/materiales/recepciones` aceptan opcionalmente:

```json
{
  "transporte": {
    "conductor": {"rut": "11111111-1", "nombre": "Conductor de ejemplo", "telefono": null, "version_conocida": 1, "actualizar_directorio": false},
    "empresa": {"rut": null, "nombre": "Transportista de ejemplo", "telefono": null},
    "vehiculo": {"patente": "ABCD12", "descripcion": "Camión"},
    "patente_carro": null
  }
}
```

Identidades y versiones guardadas en el snapshot las calcula el servidor; no se aceptan IDs suministrados por el cliente. Si se incluye `transporte`, sus datos determinan los textos compatibles `patente` y `transportista` de cabecera. Sin esa clave se conserva el contrato anterior.

Los modelos y el servicio permiten reutilizar esta base en Portería, Romana y otras operaciones; sus formularios no cambian en este PR. No se accede a bases externas ni a ODBC.

## Despliegue

Migraciones normales (`php artisan migrate --force`) y compilación de Oficina (`npm ci && npm run build`). No requiere una APK nueva: los clientes instalados continúan usando los campos compatibles. La migración agrega tablas y columnas opcionales, sin alterar stock, folios, datos documentales ni cantidades previas.

Las pruebas cubren normalización, reutilización entre clientes, cambio de camión, viajes confirmados, auditoría, conflictos de versión, reversión completa, compatibilidad anterior, permisos y búsquedas que no escriben. La interfaz se comprueba en escritorio y en pantalla de 390 px.
