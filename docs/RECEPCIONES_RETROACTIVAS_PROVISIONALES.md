# Recepciones retroactivas y provisionales de materiales (etapa 8)

## Operación

La fecha física es independiente de la fecha de emisión del documento y de las marcas de registro, autorización y confirmación del sistema. Se muestra en `APP_OPERATIONAL_TIMEZONE` (por defecto America/Santiago) y se almacena en UTC. La fecha física declarada se usa como fecha de ingreso del folio para FIFO; el movimiento de inventario mantiene la hora real en que se confirmó. Las recepciones anteriores conservan su fecha física desconocida; no se inventan datos históricos.

Un registro de un día anterior es retroactivo. Puede ser documentalmente completo o provisional. Una provisional admite guía, fotografía de documento y cantidad documental pendientes. El conteo, lo aceptado, lo rechazado y la distribución exacta en bultos siguen siendo obligatorios. Los documentos desconocidos se guardan como NULL, nunca como cero o una guía ficticia. Las conversiones conservan su factor y cantidades de entrada originales.

La confirmación normal sigue exigiendo foto documental. Una retroactiva o provisional requiere además la acción de perfil **Autorizar recepciones retroactivas y provisionales** (`recepciones.autorizar_tardios`), el permiso habitual de confirmación y un motivo de autorización. Guardar un borrador no autoriza ni crea stock. Al confirmar se generan una sola vez los folios y el ingreso de inventario; desde ese momento el material puede seguir su flujo habitual.

El plazo termina al final del quinto día corrido posterior al día físico. Ejemplo: recepción el 20 de octubre, plazo hasta el 25 a las 23:59:59 en Chile. Incluye fines de semana y respeta el horario de verano. No se reinicia al confirmar o regularizar. Si ya venció, el autorizado debe reconocer expresamente la excepción fuera de plazo, además de justificarla. El vencimiento queda visible y auditado; no elimina ni bloquea automáticamente material que ya está operando.

## Regularización

En Oficina → Materiales → Recepciones, o en el expediente móvil, abrir una provisional confirmada y seleccionar **Regularizar documentos**. Un autorizado completa guía, fecha de emisión, cantidades documentales de todos los detalles originales **en su unidad base**, fotografía y motivo. La foto se agrega previamente al expediente con el permiso habitual de gestionar recepciones. La regularización exige temporada activa y versión vigente.

Regularizar solo actualiza el respaldo documental y registra usuario, hora, motivo, valores anteriores y nuevos, documento y condición de plazo. No modifica folios, bultos, cantidades físicas, saldos, reservas, movimientos ni el snapshot original de confirmación. Funciona aunque ya existan consumos o traslados. Una provisional confirmada conserva su origen provisional aun después de regularizar. Su corrección administrativa que desmontaba y reconstruía inventario queda deshabilitada; cualquier diferencia física se corrige mediante los flujos auditados de inventario.

La lista permite filtrar pendientes, vencidas, regularizadas y retroactivas; muestra contadores de pendientes/vencidas sobre todos los resultados autorizados, no solo la página. La evidencia permanece en el archivo de eliminación si se elimina una recepción administrable. Estos son avisos en el módulo; esta etapa no envía notificaciones externas ni modifica despachos (etapa 9).

## API y compatibilidad

- POST creación / PUT borrador: campos opcionales `provisional`, `fecha_recepcion` (ISO con zona o fecha-hora local operacional), `motivo_registro_tardio`. Un cliente anterior puede omitirlos sin alterar sus hashes ni reintentos. Para una provisional sin fecha explícita se toma el momento real de registro.
- POST `/{recepcionMaterial}/confirmar`: UUID y versión habituales, más `motivo_autorizacion` y, si corresponde, `aceptar_fuera_plazo`.
- POST `/{recepcionMaterial}/regularizar`: UUID, versión, guía, fecha de emisión YYYY-MM-DD, motivo, reconocimiento de plazo y la lista exacta de `detalles: [{id, cantidad_documental}]`. Reintentar un mismo UUID con el mismo usuario y contenido devuelve el resultado; cambiar los datos con ese UUID genera conflicto. Otro usuario no puede apropiarse del UUID.
- `estado` sigue siendo borrador/confirmada/anulada. `registro` es información adicional; incluye estado documental, fecha física, plazo, autorización y regularización. `numero_guia_despacho` y `cantidad_documental` pueden ser NULL en una provisional pendiente.

## Despliegue y comprobación

Respaldar base operativa, archivos privados y configuración; instalar el código y ejecutar `php artisan migrate --force`, compilar Oficina con `npm ci` y `npm run build`, limpiar cachés y reiniciar el worker según el procedimiento habitual. Se conserva Apache y su puerto 8000. No se agregan dependencias nativas ni se cambia el runtime móvil: después de fusionar y validar, las pantallas móviles se distribuyen por OTA oficial.

Asignar el permiso de autorizar a los responsables elegidos; un usuario que solo confirma no obtiene autorización para ingresos tardíos. Probar primero en el servidor de pruebas: provisional sin documento → autorización → ubicación/consumo → agregar documento → regularización. Verificar que los folios y saldos no cambian, que un confirmador sin autorización recibe 403 y que un plazo vencido exige reconocimiento explícito. Verificar la pantalla en PDA física antes de publicar OTA.

La migración amplía el esquema y permite NULL documentales. Al revertir elimina sus campos de seguimiento, pero conserva la nulabilidad de guía/cantidad para no fabricar o destruir documentos desconocidos. Un rollback de aplicación requiere evaluar primero las provisionales pendientes.
