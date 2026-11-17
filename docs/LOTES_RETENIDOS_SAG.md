# Lotes retenidos SAG

El módulo está en Frigorífico → SAG → Lotes retenidos. Gestionan los perfiles Contraparte SAG y Administración. La identidad del seguimiento es **cliente + lote de recepción + temporada activa**; el CSG se muestra como referencia.

## Registrar y resolver

1. Seleccionar cliente e ingresar el lote de recepción.
2. Informar la detección y elegir **Con observaciones** o **Retenido**.
3. Marcar los países, bloques o todos los destinos cuyo despacho se restringe. Una observación puede no restringir destinos; una retención exige al menos uno.
4. Guardar el seguimiento. El control alcanza cualquier pallet con cajas de ese lote, incluso una sola caja, mezclas, repaletizajes y folios ingresados posteriormente.
5. Abrir el registro para confirmar resultados, ampliar destinos, liberar parcialmente o elegir **Liberado**. Cada cambio exige una resolución y conserva responsable, fecha, versión y los folios identificados en ese momento. Se puede marcar un resultado como definitivo.

Para liberar un país dentro de un bloque o una restricción global, usar **Editar países individualmente** y desmarcar los países liberados. Se guardará una selección explícita de países. Un bloque conservado como bloque utiliza sus miembros vigentes al evaluar la salida.

Los pallets continúan disponibles para asignación a cargas, movilización y carga del camión. Las alertas aparecen en las cargas, Operación ahora y labores/estado de cargas del camarero. La confirmación de salida se valida nuevamente en servidor para cada pallet y su instructivo: un destino restringido impide cerrar todo el despacho. Si existe restricción y falta el destino o el instructivo correspondiente, debe completarse antes de salir.

No se genera segregación física automática. Si el resultado la exige, se registra la instrucción de segregación y se muestra a operación y camareros para ejecutarla.

## Planillas y antecedentes

Cada registro permite descargar PDF y Excel con logo de planta, cliente/lote, detección, restricciones, resoluciones, responsables, folios y cajas afectadas. El historial conserva los folios identificados al decidir; la planilla también incluye el estado actual al emitirla. Esto permite distinguir movimientos posteriores del antecedente original.

## Integración y puesta en servicio

Ejecutar las migraciones con el procedimiento habitual y compilar los assets web. El cambio agrega dos tablas; no cambia existencias, estados físicos ni reservas existentes. La migración incorpora el permiso al perfil Contraparte SAG y a los perfiles administrativos predeterminados. Los perfiles personalizados deben incluir explícitamente el nuevo módulo.

Las alertas nativas requieren publicar la actualización de PDA/tablet; el bloqueo del despacho se aplica en servidor incluso con un cliente móvil anterior. La identificación reutiliza `trazabilidad_folio_origenes`: se requiere que la composición tenga el cliente y lote correctamente registrados. No se infiere la identidad por CSG ni por nombres parecidos.

Las correcciones del número de lote conservan el vínculo al identificador interno del lote, incluidos seguimientos registrados antes de su digitación. Los registros de otra temporada no se pueden modificar desde la temporada activa. Las resoluciones usan versión e identificador de operación para rechazar cambios concurrentes y evitar duplicados por reintentos.
