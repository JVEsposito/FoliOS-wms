# Stitch · Experimento 626

Tema personal oscuro de Oficina. Usa azul noche, azul océano y turquesa, con detalles rosa.
Las figuras aparecen en las cabeceras compartidas, junto al logo y en el lateral, también
fuera de Operación ahora. En pantallas pequeñas tienen una franja separada del texto.
No contienen controles, no interceptan clics y no se imprimen. Las seis poses son dibujos
2D distintos: saludando, abrazando un corazón, saltando, sentado, asomándose y con sueño.
Las tres figuras de la cabecera son diferentes entre sí; las otras ocupan el logo, el
lateral y la firma. No se usan figuras 3D.

## Asignación exclusiva

Después de incorporar este cambio al servidor, ejecutar desde la raíz del proyecto:

```bash
php artisan oficina:tema-stitch primera-cuenta@empresa.cl segunda-cuenta@empresa.cl
```

También se aceptan IDs internos. Las cuentas deben existir, estar activas y ser internas;
un perfil inactivo impide la asignación. Primero se validan todas las cuentas: un dato
incorrecto no cambia ninguna asignación.

El comando guarda sus IDs en `storage/app/private/temas-oficina/catalogo.json`, bajo
`personal-stitch.usuario_ids`. La lista completa reemplaza la asignación anterior.
Los correos no se incorporan al código fuente. Cambiar el correo de una cuenta no cambia
la concesión porque el permiso se liga al ID.

El selector incluye Stitch únicamente para esas cuentas. No se ofrece en los temas de
un perfil, ni se hereda por el rol Administrador. Una asignación de perfil antigua o
manipulada no amplía la lista exclusiva. La ruta autenticada de la hoja de estilo verifica
la misma asignación y devuelve 404 a las demás cuentas; los recursos se incrustan en ella,
sin publicar rutas de imágenes ni consultar proveedores externos desde el navegador.

Las cuentas activas autorizadas reciben este tema adicional aunque su perfil personalice
los demás temas. Su perfil, permisos, sesión y tema seleccionado se conservan. Pueden
seleccionarlo al recargar Oficina. Un usuario o perfil inactivo no recibe el tema.

Sin una lista válida y no vacía de IDs, Stitch permanece deshabilitado. Las demás entradas
del catálogo conservan su comportamiento por correo o por perfil.

## Retirar o cambiar la asignación

Para cambiar las cuentas, repetir el comando con la lista completa de destinatarios.
Para retirar Stitch de todas ellas:

```bash
php artisan oficina:tema-stitch --retirar
```

Cada cambio conserva una copia del catálogo anterior en `temas-oficina/respaldos/`.
Un catálogo JSON inválido se rechaza y no se sobrescribe. La escritura utiliza bloqueo y
reemplazo de archivo; no modifica usuarios ni perfiles. La retirada se refleja al refrescar
el contexto o recargar Oficina, que revalida la hoja privada.

No requiere migraciones, modificaciones del ERP ni OTA. Las ilustraciones y el CSS se
incluyen en los recursos del proyecto; no hace falta instalar dependencias adicionales.

## Ilustraciones

Los PNG se conservan como archivos originales con transparencia, separados del CSS:

- Sentado: <https://pngimg.com/uploads/stitch/stitch_PNG57.png>.
- Corazón: <https://pngimg.com/uploads/stitch/stitch_PNG52.png>.
- Saludo: <https://pngimg.com/uploads/stitch/stitch_PNG22.png>.
- Asomado: <https://pngimg.com/uploads/stitch/stitch_PNG7.png>.
- Con sueño: <https://pngimg.com/uploads/stitch/stitch_PNG66.png>.
- Salto: <https://pngimg.com/uploads/stitch/stitch_PNG35.png>.

Fuente: galería de Stitch de PNGimg. Se incluyen los seis PNG originales, sin recortes,
recoloreado ni eliminación de marcas. El tamaño y la ubicación se definen en CSS.

Personaje Stitch: Disney. Son recursos decorativos de este tema personal; no forman parte
del logotipo ni de los documentos operacionales de FoliOS.

## Verificación

- API: dos cuentas permitidas, un tercero del mismo perfil y otro administrador excluidos;
  hoja autenticada privada, rechazo de asignación por perfil, revocación, cambio de correo,
  IDs inválidos y preservación del catálogo y las sesiones.
- Oficina: cabeceras de Operación ahora y Romana en 390, 1024 y 1440 px, contraste del título,
  ausencia de desbordamiento horizontal y acceso al cierre de sesión.
