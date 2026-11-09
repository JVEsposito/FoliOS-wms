# Varias guías en un mismo camión

En Romana, crear una recepción de fruta con el cliente, temporada, especie y transporte habituales. Ingresar la primera guía y sus cantidades; pulsar **Agregar otra guía** para añadir las siguientes. Se admiten hasta 20 documentos del mismo cliente y temporada. Si corresponden a clientes distintos, registrar recepciones separadas.

Cada guía declara sus envases. Los totales del camión se calculan automáticamente; la propiedad y tara se configuran una vez por tipo de envase. El camión conserva un único ingreso, pesaje bruto y destare. En pesaje acumulativo, las tandas siguen perteneciendo a la recepción completa.

En Validación MP de PDA y tablet aparece un segmento por guía con las cantidades declaradas. Confirmar el CSG y variedad de cada segmento, revisar las tarjas y registrar el conteo real. Si una guía trae varios orígenes, agregar segmentos y asignarles esa misma guía; también pueden coincidir CSG y variedad entre guías distintas. Los segmentos deben cubrir todas las guías y sumar el conteo real por tipo. Las diferencias respecto de lo declarado se conservan por guía.

La guía se mantiene en los segmentos pendientes y lotes de materia prima. La cuenta corriente y existencias conservan una entrada por recepción y tipo: el desglose documental no genera movimientos adicionales.

Una guía vigente no puede repetirse en otra recepción del mismo cliente y temporada. La eliminación administrativa libera sus documentos y conserva el historial. La anulación ordinaria mantiene las reservas. Después de tomar la recepción en Validación MP no se pueden editar sus antecedentes documentales desde Romana, conforme al bloqueo existente.

## Instalación

1. Desplegar el backend y ejecutar `php artisan migrate --force`, además de compilar los recursos de Oficina.
2. Distribuir la actualización de ambas aplicaciones (PDA y tablet) antes de operar recepciones con varias guías. Las versiones anteriores conservan el flujo de guía única, pero no pueden confirmar una recepción nueva con varios documentos sin indicar su desglose.
3. Las recepciones históricas se migran como una guía con su texto literal. No se separan automáticamente números que se hubieran escrito juntos.

La migración conserva las guías retiradas y los enlaces de segmentos; no modifica pesos, cantidades, saldos ni movimientos históricos. No revertirla después de registrar recepciones con varios documentos sin un plan de conservación de ese detalle.
