# Chat y correo interno de FoliOS

El botón **Chat / correo** de la cabecera abre Comunicaciones sin salir de la oficina actual. En pantallas pequeñas se presenta como un icono. El indicador avisa de chats nuevos y correos sin leer.

Está disponible para usuarios internos activos. Las cuentas del portal de clientes no acceden al directorio, al chat global, a los privados, al correo interno ni a los archivos de stickers.

## Chat

- **Chat global** reúne al equipo interno. Los mensajes quedan disponibles como historial.
- **Nuevo privado** permite buscar a otra persona e iniciar o recuperar la conversación entre ambos. Desde la aplicación, sólo sus dos participantes pueden consultarla, incluso si otro usuario tiene rol de administrador.
- Escribe un texto, selecciona un sticker o combina ambos y pulsa **Enviar mensaje**.
- **Mensajes anteriores** carga otra página del historial. Si llegan mensajes mientras lees más arriba, **Ir al final** permite verlos. La lectura avanza hasta los mensajes mostrados al final; una lectura demorada no retrocede el seguimiento.
- Si un envío no se confirma, conserva el mensaje y reintenta sin modificarlo. El identificador del intento impide duplicar su entrega.

### Grupos, fotos y moderación

- **Nuevo grupo** permite elegir un nombre y agregar personas internas. En **Integrantes**, quien creó el grupo puede cambiar su nombre y su composición. Un administrador que pertenezca al grupo también puede gestionarlo. Al incorporar a una persona se le da acceso al historial del grupo.
- Cada mensaje muestra la foto de perfil del remitente. Si la foto no está disponible, se muestran sus iniciales.
- En el chat global y en los grupos de los que forma parte, el rol **Administrador** puede eliminar un mensaje para todos o silenciar temporalmente a una persona. El chat muestra que el mensaje fue eliminado y deja registro de quién lo moderó y cuándo.
- El silencio permite leer y suspende el envío de texto y stickers en esa conversación hasta que venza la duración indicada. El administrador puede levantarlo antes desde el mismo control. Las conversaciones privadas son visibles para sus participantes.

El chat se actualiza aproximadamente cada cinco segundos con el panel abierto. Las pestañas ocultas o sin conexión pausan las consultas y los errores producen una espera creciente antes del próximo intento. El historial se descarga por páginas; no se vuelve a descargar completo en cada consulta.

## Citas de operaciones y menciones

En el chat y en el correo se pueden citar registros de FoliOS y mencionar personas:

- **#** cita una operación. Escribe `#` y al menos dos caracteres del código, número, guía o patente. Aparecen hasta cinco coincidencias por tipo. Se elige con las flechas y Enter, o con el mouse. El botón **# Citar** inserta el `#` en el cursor.
- **@** menciona a alguien del equipo. Escribe `@` y parte del nombre. El botón **@ Mencionar** inserta la `@`.

Se pueden citar estos registros:

| Tipo | Se busca por |
| --- | --- |
| Folios | número |
| Recepciones de romana | número de recepción, guía o patente |
| Lotes MP | número |
| Cargas | código, orden externa o patente |
| Embarques | código o patente |
| Procesos de prefrío | código |
| Órdenes de proceso | código |
| Hidrocooler | código |
| Recepciones de fruta embalada | guía |
| Recepciones de materiales | guía o patente |
| Despachos de materiales | código |
| Inspecciones SAG | código o N° SAG |
| Guías de envases | número o guía |

Una coincidencia exacta se encuentra en cualquier temporada. Una parcial, como los últimos dígitos, se busca sólo en la temporada activa.

**Permisos.** Sólo se cita lo que el propio módulo permite consultar: quien no ve Cargas no encuentra ni puede enviar una carga. Al enviar, el servidor revisa cada cita. La etiqueta queda fija, por ejemplo `#CAR-2611-0007` o `@Javier Esposito`, así que el historial se lee igual aunque el registro cambie después. Se admiten hasta 20 citas por mensaje o correo.

**Ficha.** Cada cita se muestra como un botón. Al pulsarlo se abre una ficha con los datos del registro y un enlace al módulo, que se abre en otra pestaña. La ficha usa los permisos de quien la abre: sin acceso al módulo sólo se ve la etiqueta y el aviso «No tienes acceso…». Desde la ficha de una persona se puede abrir un privado. Los enlaces a un folio o a una recepción abren su búsqueda en Consultas; los de Prefrío abren el proceso (`/oficina/prefrio?proceso=…`).

**Menciones.** Un mensaje que te menciona se destaca y su conversación muestra **@**. El botón **Chat / correo** también muestra **@** hasta que leas el mensaje. Sólo cuentan las menciones en conversaciones que la persona puede leer: el chat global, sus privados y los grupos a los que pertenece. En el correo, la mención se destaca para los destinatarios.

**Buscar correos.** La búsqueda del correo también encuentra lo citado: `CAR-2611-0042` o el nombre de una persona mencionada.

Técnicamente, cada cita viaja en el texto como `[[tipo:id]]` y se guarda en `com_referencias`, junto con su etiqueta, para cada mensaje o correo. `GET /api/comunicaciones/referencias?buscar=` busca y `GET /api/comunicaciones/referencias/{tipo}/{id}` entrega la ficha.

## Stickers cargables

Abre **Stickers → Cargar un sticker**, escribe un nombre, elige el archivo y pulsa **Subir sticker**. Se comparte en la galería del equipo; subirlo no envía un mensaje por sí solo. Selecciónalo y pulsa **Enviar mensaje** para usarlo en la conversación elegida.

Se admiten PNG, WebP y GIF de hasta 2 MB y 1024 × 1024 píxeles, conservando transparencia y animación. Cada usuario dispone de hasta 200 cargas. El autor puede **Quitar de galería** sus stickers; los que ya se enviaron siguen visibles en el historial.

Los archivos se conservan en almacenamiento privado. La interfaz los obtiene con la sesión autenticada, únicamente al necesitarlos, sin poner credenciales en sus enlaces.

## Correo básico

**Correo** ofrece **Recibidos**, **Enviados** y **Archivados**, búsqueda y paginación. **Redactar** permite seleccionar hasta 50 destinatarios internos, un asunto y el texto del mensaje. El nombre de cada destinatario aparece antes de enviar.

Al abrir un correo recibido se marca como leído. Puedes marcarlo nuevamente como no leído, **Responder**, **Archivar** o **Restaurar a recibidos**. La lectura y el archivo pertenecen a cada destinatario: no cambian la bandeja de las otras personas. Sólo el remitente y los destinatarios acceden al contenido.

Es correo dentro de FoliOS. Esta versión no entrega mensajes a direcciones externas ni necesita credenciales SMTP. Los borradores y los intentos sin confirmar se mantienen en memoria durante la sesión de la página; revisa Enviados si abandonaste la página durante una solicitud pendiente.

## Activación y administración

Aplicar las migraciones habituales al integrar el PR. Las migraciones crean las tablas `com_*` (incluida `com_referencias` para las citas) y el canal global; no altera las reservas, movimientos ni cuentas corrientes. Las comunicaciones pertenecen a los usuarios y continúan entre temporadas. No se requiere un servicio de WebSockets o un proveedor de correo para esta versión.

Cerrar sesión o cambiar de usuario limpia el panel, los borradores y las imágenes del contexto anterior. Las respuestas que llegan tarde no se incorporan a la nueva sesión.

Los límites de envío (60 mensajes, 15 correos, 30 privados y 5 stickers por minuto) se cuentan por separado. Antes compartían el contador por usuario con los demás límites de FoliOS: chatear podía bloquear un minuto, por ejemplo, la subida de un sticker o un ajuste de stock.
