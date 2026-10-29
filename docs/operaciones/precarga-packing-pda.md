# Precarga automática al escanear un pallet de packing

El validador escanea el folio en Validación PT. La PDA consulta primero los intentos
de FoliOS: un observado recupera su captura anterior y una decisión final conserva
su protección. Para una captura nueva solicita los datos de packing automáticamente.
Los campos permanecen editables una vez recibidos. Solo Aprobar registra el pallet
en Frigorífico; la consulta de packing no crea folios ni validaciones.

## Datos y espera

- Se reutiliza la copia del pallet si corresponde a la configuración vigente y está
  dentro de la vigencia configurada (20 minutos por defecto).
- Si falta, salió según la copia anterior o está desactualizado, se encola una
  consulta de ese número con los mismos filtros, bodega y temporada del mapeo.
  Conserva ceros iniciales; no descarga toda la vista como sustitución.
- La pantalla muestra el avance y ofrece **Continuar manualmente**. Al usarlo, cambiar
  de folio o salir de la pantalla, un resultado tardío no sustituye la captura.
  Durante la espera se bloquean los campos que la propuesta va a completar.
- Se cargan cajas, tipo, fecha, cliente, marca, categoría y composición por CSG,
  lote y proceso. Si falta una dimensión del producto, se conservan las demás que
  puedan seleccionarse con certeza en el catálogo. Una fecha ausente queda vacía.
- Lo no homologado se indica para completar. Un fallo de red/permisos/consulta se
  explica como fallo; una respuesta vacía correcta indica que no se encontró el
  folio con los filtros de la fuente. No se confunden las dos situaciones.
- Las correcciones y la huella de la copia mostrada siguen registrándose en la
  validación. Repetir su UUID no crea un segundo pallet.

## Servidor y conexión

El acceso a la base externa lo realiza el servidor Laravel, usando la conexión
cifrada y el mapeo configurados en Administración. Comprobar **Probar conexión**,
la muestra y los filtros desde esa instalación. El servidor debe poder llegar a
la IP/puerto de la base de packing; la PDA se conecta solamente a FoliOS.

Debe estar atendida la cola exclusiva:

```sh
php artisan queue:work integraciones --queue=integraciones --sleep=1 --tries=1 --timeout=180
```

El proceso requiere reinicio automático para operación permanente. En Windows,
mantenerlo abierto durante la comprobación manual. El worker general y el scheduler
continúan con sus procesos existentes.

## Contrato y alcance de la lectura

`POST /api/validacion/precarga-origen` recibe `numero_folio` y `temporada_id` con
permiso `validar-pallets`. Devuelve 200 con una propuesta local vigente o 202 con una
operación. La PDA sondea exclusivamente el GET de esa operación; no repite el POST.
Solo su solicitante con permiso vigente puede consultar el resultado temporal.

El worker revalida conexión, configuración, temporada y existencia previa del folio.
Los SELECT externos se ejecutan en transacciones de solo lectura. La consulta queda
identificada por `lecturas_stock_origen.numero_pallet`; las lecturas históricas y
completas conservan null. Solo ese pallet se actualiza o marca ausente. Un fallo
conserva la copia anterior. Una búsqueda puntual no renueva la fecha global de stock,
no retira otros pallets y no permite declararlos «Solo en FoliOS» sin lectura completa.

El GET anterior de precarga se conserva para las aplicaciones existentes. La PDA
nueva utiliza ese GET si encuentra un servidor que aún no admite el POST.

## Despliegue y aceptación

1. Actualizar backend y ejecutar `php artisan migrate --force`.
2. Regenerar la caché de configuración de la instalación y reiniciar los procesos
   web y de colas, incluido Integraciones.
3. Publicar OTA para los canales que utilicen Validación PT, incluido `pda` para la
   PDA de esta operación. No hay dependencias nativas nuevas ni exige otra APK.
4. Con un folio real recién ingresado en packing, escanear y comprobar la precarga.
   Corregir cajas y completar un dato faltante; aprobar y comprobar un solo folio,
   su captura y la constancia de lo corregido.
5. Comprobar folio ya validado, no encontrado, origen inaccesible, CSG/envase sin
   equivalencia, espera con continuación manual y respuesta tardía tras otro escaneo.
   Comparar los otros pallets y la fecha de la última lectura completa antes/después.

La migración no se revierte cuando ya hay lecturas puntuales: quitar la columna
haría que el código anterior las interpretara como lecturas completas. Conservar
sus metadatos; antes de un rollback de código se requiere resolver las operaciones
pendientes y una lectura completa correcta de la fuente.

Las pruebas usan una base MySQL aislada y un origen simulado. La latencia y los
permisos reales de la vista del packing se comprueban en la instalación de planta.
