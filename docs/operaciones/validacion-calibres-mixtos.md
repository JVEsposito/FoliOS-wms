# Validar pallets con varios calibres

En PDA y tablet, completa el producto del pallet y agrega una línea por cada combinación de calibre, CSG, lote de materia prima y proceso. El calibre del producto es la selección base de las líneas nuevas; cada línea permite elegir otro calibre habilitado para su CSG, conservando especie, variedad y envase.

Por ejemplo, un pallet de 120 cajas puede contener 80 cajas 2J y 40 cajas 3J, incluso del mismo CSG, lote y proceso. La suma debe ser 120. El folio queda con calibre MIX y conserva el detalle de ambos calibres con sus cajas para etiquetas y operaciones posteriores.

Las correcciones de Oficina permiten modificar el desglose. Al abrir o retomar un registro pendiente se conservan los calibres de cada línea. Una corrección simple de un pallet mixto conserva su composición; cambiar su producto requiere enviar el detalle completo.

## Compatibilidad de la API

Cada línea de `composicion` acepta `articulo_validacion_id`. El artículo principal del envío corresponde al de su primera línea. El servidor comprueba temporada activa, artículo y combinación habilitados, variedad autorizada por CSG, un solo cliente y marca, producto compatible y suma de cajas.

Si una PDA anterior omite el artículo de la línea, se utiliza el principal, como antes. Los campos opcionales ausentes no se incorporan al hash de la operación, para conservar los reintentos pendientes. No se requiere migración de base de datos.

La lectura de packing conserva el calibre en cada componente y deja de sumar calibres distintos en una misma línea. Después de actualizar el servidor se debe ejecutar la lectura habitual de stock para renovar las copias guardadas antes de este cambio.
