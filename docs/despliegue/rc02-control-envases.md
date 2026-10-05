# RPR-01 → catálogo/reparto de envases → RC-02

Orden: #386 (fusionado), PR A #387, luego PR B. B apunta a la rama de A; cambiar su base a main después de fusionar A.

## Antes de probar

1. Ejecutar `php artisan migrate` después de cada despliegue. Las migraciones agregan configuración y tablas; no generan inspecciones ficticias ni recalculan recepciones históricas.
2. Administración → Accesos → Formatos de registro: comprobar RPR-01 y RC-02 versión 1, fecha 15-09-2025 y localidad Rengo. Antes de operar, ambos deben estar activos.
3. Revisar pesos de referencia: cereza bins 210 kg y totes 8,75 kg; sugerencia totes. Configurar caja 3/4 para la especie antes de seleccionar reparto mixto.
4. Publicar OTA primero en `pda-pruebas` y reabrir la PDA. B exige inspección en MP; la PDA antigua no podrá completar una validación hasta actualizarse.

## Prueba conjunta

- Cereza con bins, totes y esponjas; dos segmentos con 18 y 24 totes. Completar limpieza/condición y los tres controles en PDA. Descargar RC-02 Recepción antes del destare: sin hora de salida. Al salir con los mismos, completar inspección de salida y guía; verificar sugerencia **solo totes** por ser anidados, suma exacta del neto en ambos lotes y saldo cero. Descargar ambos RC-02 y revisar guía del cliente en recepción y guía de salida en despacho.
- Bins y cajas 3/4 con fruta independiente, camión vacío: seleccionar ambos tipos de reparto, revisar pesos de referencia, tara de todos los tipos, remanente final exacto y disponibilidad de **solo RC-02 Recepción**.
- Más o menos envases: incorporar un tipo adicional, exigir su tara e inspección, comprobar diferencias de saldo y cantidades de salida en RC-02.
- Publicar versión 2 de RC-02: reimprimir documentos existentes (versión 1) y emitir uno nuevo (versión 2).
- Consultar PDF en blanco `/api/romana/control-envases/en-blanco`, con permiso de consulta romana.

## Correcciones y registros históricos

La corrección supervisada de inspección usa `PUT /api/romana/recepciones/{id}/inspecciones-envases/{recepcion|despacho}` con `operacion_id`, `version_conocida`, `motivo` e `inspeccion_envases`. Requiere `corregir-recepciones-romana` y temporada activa. La API devuelve la versión actual y la recepción expone sus inspecciones. Cada cambio conserva antes/después, actor, motivo y operación; no modifica la versión documental original ni cantidades por un valor enviado por el cliente.

Las recepciones ya validadas antes de B no reciben inspecciones inventadas: no aparece su botón RC-02 hasta registrar una inspección supervisada, con `version_conocida: 0`. RPR-01 sigue disponible. Si una corrección de salida deja el camión vacío, RC-02 Despacho deja de estar disponible; la inspección anterior y los eventos de corrección de salida permanecen como auditoría.

No se ejecutó despliegue, migración en servidor ni publicación OTA como parte de estos PR.
