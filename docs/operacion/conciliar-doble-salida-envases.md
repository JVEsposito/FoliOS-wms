# Salida de envases registrada en Romana y Despacho

Una guía de salida debe descontar una sola vez. El destare de Romana ya genera
salida de cuenta y existencia; no debe confirmarse otro despacho para respaldar
el mismo traslado. Desde esta corrección, el servidor rechaza la segunda salida
para el mismo cliente, temporada y número normalizado, en ambos órdenes. Se
conservan los reintentos de una misma operación.

Si se confirmó un despacho por error antes del destare, anular ese despacho
antes de cerrar Romana, verificando que las cantidades y la guía correspondan.
La anulación compensa sus movimientos y deja a Romana registrar la única salida.
No cambiar el número de guía para eludir la advertencia.

## Historial ya duplicado

### Desde la pantalla de FoliOS

1. Ingresa con un **administrador autorizado** a **Materia prima → Cuenta Envases**.
   Selecciona la temporada activa y, si corresponde, el cliente.
2. Pulsa **Conciliar duplicados**, junto a Actualizar, en la cabecera de la página.
   **Guía de conciliación** abre las instrucciones sin salir de FoliOS, también
   desde el propio diálogo.
3. Busca por cliente, número de guía de ingreso, guía de salida o correlativo de
   recepción. La lista tiene páginas de 15 parejas y muestra coincidencias del
   mismo cliente, temporada y número normalizado de **guía de salida**. El ingreso
   puede tener otro número (por ejemplo, ingreso 11893 y salida 2236).
4. Pulsa **Comparar documentos** en la pareja exacta. Comprueba los números REC,
   GDE y de guías con sus respaldos. Si hay varias coincidencias, no elijas por
   cercanía de fecha.
5. Elige **Conservar las cantidades de Romana** o **de Despacho** según la salida
   física comprobada y pulsa **Previsualizar conciliación**. No hay selección
   automática y todavía no se modifica ningún saldo.
6. Revisa cada envase y propiedad: cantidades de ambos flujos, diferencia
   (Despacho menos Romana), **salida final** y **reposición en cuenta y planta**.
   Los envases que figuran en un solo flujo permanecen registrados.
7. Escribe el **motivo y respaldo revisado**, marca que verificaste las cantidades
   y pulsa **Confirmar conciliación**. La sesión identifica al responsable; no
   necesitas ingresar su correo ni ejecutar comandos.
8. Lee la confirmación y consulta los saldos y la existencia actualizados. El
   estado de cuenta netea la salida corregida; el kardex conserva originales y
   compensaciones. Repetir una operación ya aplicada no crea otro abono.

Si cambias de pareja o de origen de cantidades, debes volver a previsualizar.
Si un documento cambió o se perdió la respuesta al confirmar, la pantalla exige
otra previsualización; esta informa si la pareja ya fue conciliada.

### Números distintos por error de digitación

No necesitas corregir el número de una guía para que el conciliador la encuentre.
En **Conciliar duplicados**, cambia **Forma de búsqueda** a
**Comparación manual · guías distintas**, selecciona el cliente y busca una guía
o un correlativo completo REC/GDE (al menos 3 caracteres). La lista muestra ambos
números de salida y la guía de ingreso. Seleccionar un mismo productor o tener
cantidades parecidas no demuestra que sea la misma salida: verifica los respaldos.

Por ejemplo, para **GDE-2611-0006, guía 2235**, y **REC-2611-0009, salida 2234**:

1. Selecciona FALCO FRUIT y busca 2235 o REC-2611-0009 en comparación manual.
2. Elige esa pareja, comprueba ambos respaldos y marca que los números distintos
   corresponden a la misma salida física.
3. Elige las cantidades comprobadas de Romana o Despacho y previsualiza. Si ambos
   tienen 14 bins y 340 totes, quedará una sola salida de esas cantidades. Las 14
   esponjas que figuran solo en Despacho se conservan.
4. Indica el error de digitación y el respaldo en el motivo y confirma. Se guardan
   los dos números originales, los documentos vinculados y el responsable.

La comparación manual conserva la restricción de cliente y temporada. No une
documentos automáticamente, no renumera guías y no cambia originales.

**Recepciones eliminadas:** REC y GDE son correlativos independientes. Eliminar
REC-2611-0006 no anula GDE-2611-0006. La eliminación de una recepción revierte sus
entradas y salidas propias, que quedan neteadas en el estado de cuenta; un
despacho independiente conserva su salida hasta anularlo o conciliarlo con la
recepción vigente que corresponda. Las recepciones eliminadas o anuladas no se
ofrecen ni se aceptan como contraparte de una salida vigente. Si el historial no
conserva sus reversas completas, requiere revisión individual antes de generar
otra compensación.

**Si no aparece o no permite conciliar:** revisa la temporada activa, cliente y
guía de salida. La recepción debe estar cerrada y el despacho confirmado. Las
parejas ya conciliadas no figuran entre las pendientes. Los casos con correcciones
anteriores, sin origen físico o con documentos incompatibles requieren revisión
individual; no cambies la guía para forzar una coincidencia.

**No reemplazar por apertura/ajuste o corrección de saldo anterior:** esos flujos
cambian solo la obligación de cuenta. Esta conciliación corrige a la vez cuenta
y existencia y conserva la trazabilidad de la salida física.

### Alternativa por consola para soporte

No ocultar filas ni modificar cantidades originales. La conciliación crea
compensaciones de cuenta **y existencia**, con responsable, motivo y cantidades
revisadas. Solo el administrador autorizado para ajustar cuentas puede aplicarla.

Para la guía de ingreso 11893 y salida 2236, comparar ambas alternativas:

```sh
php artisan envases:conciliar-salida 11893 2236 --conservar=romana
php artisan envases:conciliar-salida 11893 2236 --conservar=despacho
```

También se aceptan UUID o correlativos REC/GDE. Si la referencia no es única,
se exige una identificación más precisa. No se elige por fecha aproximada.
Si los números de salida difieren, agrega `--guias-distintas-verificadas` tanto al
diagnóstico como a la aplicación, solo después de comprobar ambos respaldos.

La captura reportada muestra 42 bins en ambos flujos, 1.002 totes en Romana y
1.009 en Despacho. No permite determinar cuál de esas cantidades refleja la salida
real. Comprobar el respaldo físico/guía antes de seleccionar la fuente. Los 14
envases de esponja que aparecen solo en Despacho permanecen registrados con
cualquiera de las alternativas.

La previsualización entrega un hash del plan. Para aplicar **la alternativa
revisada**, utilizar ese mismo hash, el administrador responsable y el motivo:

```sh
php artisan envases:conciliar-salida RECEPCION_UUID GUIA_UUID \
  --conservar=romana --aplicar --hash=HASH_DE_LA_PREVISUALIZACION \
  --usuario=administrador@planta.cl \
  --motivo="Cantidades verificadas con respaldo físico de la salida"
```

Si cambia un documento o el plan, se rechaza el hash anterior. Repetir una
conciliación aplicada no crea más movimientos. No se cambia de fuente después
de aplicarla ni se mezclan automáticamente correcciones previas de esos asientos.
Los despachos de envases de cliente o arrendados que perdieron su origen físico
requieren revisión individual; esta herramienta no inventa un origen para ellos.

El estado de cuenta, PDF, Excel y resumen CSG netean la compensación con su
salida original. El kardex conserva ambas para auditoría. Si después se anula la
guía de despacho, solo se revierte su saldo pendiente de compensar; cuando se
había conservado Despacho, vuelve a quedar vigente la salida de Romana. Así la
anulación posterior no duplica abonos ni borra la salida física documentada.

La migración incorpora coordinación e índices de documentos; **no repara saldos
automáticamente**. Debe ejecutarse antes de desplegar las nuevas escrituras.
