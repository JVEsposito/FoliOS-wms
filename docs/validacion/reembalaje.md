# Reembalaje: conversión de formatos con control de kilos

Disponible en Oficina → Repaletizajes → modalidad **Reembalaje**, y en PDA/tablet desde **Reembalaje · convertir formatos**.

Cada operación toma **un folio completo**, pallet o saldo, de la temporada activa. Para trabajar una parte, dividir primero el folio en Oficina. Puede producir entre uno y veinte folios de salida, cada uno con su formato. La primera salida puede conservar el folio original; las demás deben llevar números nuevos. No permite reutilizar otro folio ya existente.

La cuadratura usa kilos **netos comerciales**, calculados con las cajas y los kilos por caja configurados en el catálogo de envases. No deduce pesos del nombre del envase ni utiliza el peso bruto o la tara del pallet.

| Entrada | Salida | Merma explícita |
| --- | --- | --- |
| 100 cajas × 5 kg = 500 kg | 200 cajas × 2,5 kg = 500 kg | 0 kg |
| 100 cajas × 5 kg = 500 kg | 200 cajas × 2 kg = 400 kg | 100 kg |
| 1 caja × 5 kg = 5 kg | 1 caja × 2 kg + 1 caja × 3 kg = 5 kg | 0 kg |

1. Elegir turno y consultar el folio. Si faltan pesos en el catálogo o el MIX no tiene cantidades por componente, completar esos antecedentes antes de operar.
2. Configurar cada salida: conservar o cambiar folio, formato, tipo pallet/saldo y capacidad. Registrar sus cajas por CSG, variedad, calibre, fecha y demás componentes de origen.
3. Declarar la merma por componente y su motivo cuando sea mayor que cero. El sistema no convierte automáticamente diferencias en merma.
4. Confirmar cuando cada componente cuadre: **entrada = salida + merma**. Un sobrante de un CSG no compensa el faltante de otro.
5. Imprimir las etiquetas de planta con el formato y cantidades nuevos.

Se calculan y comparan enteros en diezmilésimas de kilo (0,0001 kg), sin tolerancias implícitas. Los pesos quedan congelados en la operación y composición resultante. Un cambio posterior del catálogo no reescribe los kilos de un reembalaje ya confirmado. Si el folio o sus formatos cambian mientras se prepara la operación, se debe volver a consultar el origen.

Los folios reservados para cargas o en prefrío activo siguen sujetos a los bloqueos operacionales existentes. La operación conserva genealogía, CSG, productor/predio, lote, proceso y fecha de embalaje de cada componente.

Si existen condición o autorizaciones SAG, la pantalla avisa antes de confirmar: se retiran de los nuevos formatos para revisión. La anulación, cuando no existen movimientos u operaciones posteriores, restaura el original y sus autorizaciones anteriores y retira todas las salidas nuevas. También funciona cuando se conservó el número original y se crearon otras salidas.

## Registro y revisión

El historial muestra kilos de entrada, salida y merma. En Oficina, **Descargar registro de reembalaje** entrega un CSV por operación con entradas, salidas, formatos, cajas, kilos, merma y responsable. Se genera desde los snapshots históricos e indica si la operación fue anulada. El reembalaje utiliza este registro propio: no se mezcla con RRPL-01, cuya conservación de cajas corresponde al repaletizaje.

La auditoría operacional verifica kilos para reembalaje y sigue verificando cajas para las modalidades anteriores. Detecta una cantidad de salida alterada aunque no cambien los totales declarados en el encabezado.

## Instalación

Desplegar backend, ejecutar `php artisan migrate --force`, compilar Oficina y distribuir las actualizaciones Android de PDA y tablet. La migración sólo agrega campos opcionales; no recalcula operaciones anteriores. Las modalidades existentes conservan su contrato y hash de idempotencia.
