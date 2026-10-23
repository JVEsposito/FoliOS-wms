# Permisos por acción de Materiales

PR 5 del plan. Se configura en Oficina → Accesos → Perfiles → Acciones de Materiales. La oficina habilitada, el perfil activo, el modo Solo consulta y el espacio autorizado de la tablet siguen siendo requisitos. Una acción no abre otras oficinas ni concede administración de usuarios o cámaras.

## Facultades separadas

| Área | Acciones configurables |
| --- | --- |
| Recepciones | Crear/editar borradores y adjuntar fotos; confirmar; corregir administrativamente; anular; eliminar con evidencia; imprimir |
| Despachos | Gestionar; retirar materiales; cancelar |
| Inventario | Aprobar tomas y aplicar ajustes; corregir ítem; transferir entre clientes; bloquear/desbloquear; editar niveles de stock y fotos |
| Catálogos y recetas | Administración de catálogos; administración de recetas |
| Transformaciones | Planificar; operar y registrar consumos; revertir |
| Regularizaciones | Autorizar recepciones y despachos retroactivos/provisionales, preparado para los PR 8 y 9 |

Crear un borrador no permite confirmarlo, anularlo o eliminarlo. Se puede delegar confirmación sin creación. Corregir y eliminar son independientes, igual que corregir ítem, transferir cliente y bloquear. Cancelar un despacho y revertir una transformación no necesitan recibir también el permiso para crearlos o planificarlos.

La corrección administrativa mantiene sus validaciones anteriores: puede reconstruir el inventario de una recepción confirmada cuando los folios siguen siendo administrables. El permiso de corrección comprende esa operación completa; no sustituye el permiso de confirmación directa de borradores.

Supervisar una toma y decidir diferencias no permite aplicar ajustes sin `inventario.aprobar_ajustes`. Se comprueba en el servicio antes de iniciar la mutación, también para llamadas directas. Se conserva el requisito previo de nivel Administrador o Supervisor de materiales para gestionar tomas.

La supervisión de borradores y anulaciones se conserva al retirar el permiso de anular: la lectura se resuelve aparte de las escrituras. Los límites de lectura del modo Solo consulta se mantienen.

Los permisos de regularización no habilitan por sí solos un ingreso retroactivo ni provisional. Ese circuito, con cinco días corridos, se implementará en los PR 8 y 9. Los perfiles anteriores no reciben automáticamente estas dos facultades nuevas; el Administrador protegido conserva acceso completo.

## Compatibilidad y sesiones

La migración agrega `perfiles_acceso.permisos_materiales`, sin modificar usuarios ni permisos actuales:

- `null`: mantiene las facultades anteriores de las oficinas y el nivel operacional; no incluye autorizaciones futuras salvo en el Administrador protegido.
- Una lista explícita limita las acciones de este catálogo. `[]` deniega todas las acciones listadas. Otros permisos, como mover material en cámaras, conservan su control existente. Para impedir toda escritura se utiliza **Solo consulta**.
- Los usuarios sin perfil conservan su matriz histórica de roles.

El editor presenta las facultades actuales de los perfiles anteriores. Al guardar envía una selección explícita. Las nuevas selecciones no se marcan automáticamente por añadir una oficina; las acciones incompatibles se desmarcan y deshabilitan. El servidor rechaza claves desconocidas, acciones sin oficina relacionada y escrituras asignadas a Solo consulta.

Un cliente anterior que omita `permisos_materiales` conserva la restricción guardada. Enviar `null` no permite restablecer las facultades anteriores: se rechaza. Quitar una oficina también retira sus acciones explícitas. Modificar la selección revoca las sesiones de los usuarios del perfil, igual que un cambio de módulos; deben volver a iniciar sesión para recuperar sus capacidades vigentes. El Administrador protegido sigue sin poder editarse.

## Configuración de cámaras

Ya se controla con el módulo independiente **Configuración de cámaras** (`administracion.camaras`). Se asigna al perfil de la persona responsable y se retira de los perfiles operativos que no deban administrarlas. Habilitar Inventario o acciones de Materiales no concede ese módulo ni acceso a la administración de usuarios. Un perfil Solo consulta puede leer la configuración si tiene ese módulo, sin modificarla.

Este PR no reasigna perfiles de personas reales ni modifica la estructura de cámaras.

## API, Oficina y tablet

`GET /api/administracion/perfiles-acceso` incorpora `catalogo_permisos_materiales`; cada perfil devuelve su selección y `permisos_materiales_efectivos`. El `POST/PUT` de perfiles acepta opcionalmente la lista de claves. La identidad de Oficina y tablet incorpora capacidades separadas para confirmar y eliminar recepciones, aprobar ajustes y las autorizaciones futuras.

Las comprobaciones se aplican en las rutas, solicitudes y reglas centrales de autorización, independientemente de los botones. Oficina separa Confirmar, Editar y Eliminar, y oculta la aprobación de tomas cuando corresponde. La aplicación móvil usa el permiso de confirmación separado y admite servidores anteriores.

Las APK instaladas reciben las restricciones de la API al reiniciar sesión. Para incorporar la nueva visibilidad independiente del botón Confirmar en Android/PDA hay que publicar una APK con este cambio; una APK anterior puede mostrar una acción que el servidor rechazará.

Despliegue web: migración normal y compilación de Oficina. No modifica cantidades, existencias, documentos ni datos de bases externas.

Pruebas: matriz cruzada de acciones y alias, oficinas faltantes, perfiles inactivos, Solo consulta, ámbito del token de tablet, aprobación de inventario, revocación de sesiones, compatibilidad con clientes anteriores, restricciones API y editor en escritorio/pantalla pequeña.
