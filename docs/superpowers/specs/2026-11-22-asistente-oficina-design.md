# Asistente de Oficina: diseño

**Fecha:** 22-11-2026
**Estado:** propuesto, pendiente de aprobación.
**Alcance:** FoliOS 1.x, solo Oficina web.

## Intención

Hoy, responder preguntas sobre la operación obliga a abrir varias oficinas y cruzar datos a mano: cuánto demoró algo, cuántos kilos o cajas hay, si una tara está bien. El asistente responde esas preguntas en español dentro de Oficina, con los datos vivos de FoliOS y sin cambiar nada.

Hay dos condiciones que mandan sobre el resto:

- **Costo acotado.** El usuario descartó un asistente de pago con consumo abierto porque «tanta consulta agotaría todo». Por eso el asistente funciona completo sin IA, y la IA (Claude Haiku 5.5) es opcional, con un tope de gasto que nunca se supera.
- **Potencia sin IA.** El modo sin IA no puede «quedarse sin respuestas en nada». Nunca termina en un callejón sin salida, y lo que no entiende queda registrado para ampliarlo.

## Qué debe responder

La lista es del usuario: «tiempos de todo, kilos, cajas, repas, saldos... formatos, kilos por CSG, posibles errores en la tara/destara (comparativas), consumos».

| Tema | Ejemplos de preguntas | Fuente en FoliOS |
| --- | --- | --- |
| Tiempos | Tiempo en planta por camión, espera de destare, duración de Validación MP, proceso de prefrío contra su objetivo, andén, carguío y despacho. Cada uno por día, cliente o turno, con promedios y los más lentos. | `recepciones_romana` (`ingreso_at`, `ingreso_confirmado_at`, `salida_at`, eventos), Validación MP, procesos `PF-*`, cargas y presencias en andén |
| Kilos | Bruto, tara y neto por recepción, cliente, especie, día o temporada; kilos por CSG | Romana, guías de recepción, lotes de materia prima |
| Cajas | Por folio, carga, cliente y especie; cajas por CSG dentro del pallet | Folios, cargas |
| Repas | Repaletizajes con su genealogía de folios | Módulo de repaletizajes |
| Saldos | Saldos vigentes, dónde están y cuántas cajas tienen | Folios y ubicaciones |
| Formatos | Cajas y kilos por formato de embalaje de PT | Validación PT. El usuario confirmó que «formato» significa formato de embalaje. |
| Consumos | Consumo de materiales por OT y contra el estándar, con sus diferencias | Consumos por OT y estándares de Materiales |
| Revisión de tara y destare | «Revisa las taras de hoy», «¿cómo viene el CSG 12345?» | Ver la sección siguiente |

## Revisión de tara y destare

Usa los mismos criterios con los que el usuario revisa hoy: «miro el promedio por tote neto (rango entre 8,2 y 9,5kg es lo normal) miro que la tara del envase este correcta y las esponjas y tambien hago historico por CSG».

1. **Neto por tote:** kilos netos de la recepción divididos por la cantidad de totes. Se marca si queda fuera del rango normal, indicando si quedó por debajo o por encima.
2. **Tara del envase:** la tara aplicada debe coincidir con la tara oficial vigente que conserva la recepción (`docs/MODULO_ROMANA.md`, «Taras oficiales de envases»). Cualquier diferencia se marca.
3. **Esponjas:** su tara es variable y se pesa en cada camión. Se compara la tara unitaria medida con la historia de esa misma esponja.
4. **Histórico por CSG:** el neto por tote de la recepción contra el promedio de ese CSG en la temporada activa y en la anterior.

**Rangos configurables.** Los rangos se guardan en Administración, por especie y por envase, y los edita un usuario autorizado. El primer valor es 8,2 a 9,5 kg para tote. Ningún umbral vive en la interfaz ni en el código.

**Margen de las esponjas.** Se calcula desde la historia, como confirmó el usuario: se marca lo que se aleja más de lo habitual, por ejemplo dos desviaciones estándar. El valor queda configurable igual que el rango.

## Objetivos

- Responder en lenguaje natural, sin IA, todas las preguntas del catálogo, aunque se escriban de muchas formas.
- Usar Claude Haiku 5.5 opcionalmente para preguntas libres y combinaciones que el catálogo no cubre, con gasto acotado.
- Respetar en cada respuesta los permisos y el alcance por área del usuario.
- Registrar cada pregunta que no se pudo responder, para ampliar el catálogo.

## No objetivos

- Escribir o cambiar datos. Es solo lectura, sin excepciones en esta versión.
- Tablet, PDA y portal de clientes.
- Entrenar un modelo propio.
- Reemplazar las oficinas. El asistente responde y enlaza a la oficina donde se trabaja el dato.

## Antiobjetivos

Si pasa cualquiera de estas cosas, el asistente falla, aunque técnicamente funcione:

- **Inventar una cifra.** Toda cifra sale de una consulta. Si no hay dato, lo dice.
- **Enviar fuera de la planta información de RRHH o Finanzas,** o nombres de personas y conductores.
- **Gastar más de lo fijado.**
- **Interrumpir como el clip de Office.** El asistente nunca se abre solo, ni sugiere nada por iniciativa propia, ni tapa el trabajo.
- **Dar una respuesta que el backend no autorizaría al mismo usuario.**

## Restricciones

- **Toda regla de negocio y toda autorización viven en Laravel** (ver `CLAUDE.md`). Ocultar en la interfaz no reemplaza la validación.
- **Temporada:** las consultas leen la temporada activa por defecto. Una pregunta explícita por otra temporada usa los históricos que ya existen, y la respuesta deja claro de qué temporada habla.
- **Habilitación por perfil:** un módulo nuevo en `CatalogoModulosAcceso`, habilitado por perfil de acceso igual que los demás. Los usuarios con rol `cliente` nunca lo tienen.
- **Datos que nunca salen de la planta:** RRHH y Finanzas, en todo modo. Las preguntas sobre esos dominios las responde solo el intérprete sin IA, y solo a quien tenga esos permisos. Los nombres de personas y conductores se omiten de todo lo que se envía a Anthropic. Los datos operacionales sí pueden salir: kilos, tiempos, cajas, taras, CSG, consumos, clientes y productores.
- **Red:** el camino con IA necesita que el servidor de planta tenga salida HTTPS hacia la API de Anthropic. Sin esa salida, el asistente funciona igual en modo sin IA.
- **Diseño visual:** sigue Gajos vivos (`docs/diseno/gajos-vivos/README.md`), con códigos en mono, estados con glifo y cantidades en celdas.

## Enfoques considerados

1. **Solo Claude.** Es el que mejor entiende preguntas libres, pero tiene costo por pregunta y depende de la red. El usuario lo descartó como única vía.
2. **Modelo gratuito local** (por ejemplo, con Ollama). No tiene costo por pregunta, pero requiere hardware con GPU o mucha memoria, es más lento, se equivoca más al elegir qué consultar y hay que mantenerlo. Se descartó.
3. **Sin IA,** con preguntas reconocidas por reglas. No tiene costo ni riesgo, pero su cobertura se limita al catálogo.

**Elegido: preparar a la vez el 1 (con Claude Haiku 5.5) y el 3, sobre un solo catálogo de consultas, con un modo mixto.** La inversión principal, el catálogo, sirve a los dos. El modo mixto usa la IA solo en lo que las reglas no entienden, y así resuelve la tensión entre costo y cobertura.

## Arquitectura

```text
Gajo flotante (Oficina) ─► POST /api/asistente/preguntas  (auth:sanctum + módulo habilitado)
                              │
                 ┌────────────┴────────────┐
           Intérprete sin IA         Intérprete Haiku
           (reglas, entidades)       (herramientas = catálogo)
                 └────────────┬────────────┘
                     Catálogo de consultas
           (solo lectura, filtrado por AlcanceOperacionalUsuario)
                              │
                   Servicios existentes de cada dominio
```

### Catálogo de consultas

- Cada consulta es una clase en `app/Services/Asistente/Consultas/` con:
  - nombre y descripción en español;
  - parámetros tipados (rango de fechas, cliente, especie, CSG, código…);
  - permiso requerido;
  - marca de dominio sensible (RRHH o Finanzas);
  - un método que devuelve un resultado estructurado: cifras, filas y un enlace a la oficina correspondiente.
- Reutiliza los servicios existentes (`app/Services/Consultas/*`, `ServicioPanelGerencial`, los servicios de Romana, Cargas, Prefrío y Materiales). Cuando falta un cálculo, se agrega en el servicio del dominio, no en el asistente.
- El mismo catálogo se expone como herramientas a Haiku, excluyendo las consultas de dominio sensible.

### Intérprete sin IA

- **Reconocimiento de entidades:**
  - códigos (`REC-…`, `CAR-…`, `PF-…`, folio, lote, guía, patente) con los patrones de cada correlativo;
  - clientes, productores, CSG, especies, materiales y formatos, por coincidencia aproximada contra los maestros de la base;
  - fechas relativas: «hoy», «ayer», «esta semana», «la temporada pasada».
- **Intención:** se elige por palabras clave y sinónimos de cada consulta («destare», «tara», «salida»…), con un puntaje.
- **Si no está seguro, nunca deja en blanco:** muestra las tres consultas más parecidas, ya completadas con lo que reconoció, más la búsqueda operacional con el término.
- **Un código suelto** («REC-2611-0108», «CAR-000059») abre directo el expediente que le corresponde.

### Intérprete Haiku

- Usa el modelo `claude-haiku-5-5`, con el SDK oficial de PHP y las consultas del catálogo como herramientas.
- Solo lectura. Responde únicamente con cifras que vienen de las herramientas, y las cita en la respuesta.
- **Prompt caching** para el prompt del sistema y las definiciones de herramientas, que son estables.
- **Filtro de salida:** antes de enviar un resultado a Anthropic se eliminan los campos de personas y conductores, y se bloquea cualquier consulta de RRHH o Finanzas.

### Qué se envía a Anthropic (decisión del diseñador)

Esto aplica la restricción del usuario sobre RRHH, Finanzas y nombres de personas a todo lo que sale de la planta, no solo a los resultados de las consultas:

1. **Revisión previa obligatoria.** Toda pregunta pasa primero por el intérprete sin IA, también en modo Haiku. Si detecta un dominio sensible (RRHH o Finanzas, por palabras clave y por entidades) o el nombre de una persona registrada en los maestros (trabajadores, conductores), la pregunta no sale. Se responde localmente si el usuario tiene permiso; si no, se rechaza.
2. **Historial filtrado.** Haiku recibe solo los turnos de la conversación respondidos con consultas no sensibles. Los turnos de RRHH o Finanzas nunca van en el historial.
3. **Códigos en vez de nombres.** Lo que va a Haiku identifica a productores, clientes y CSG por su código, nunca por nombre, porque muchos productores son personas naturales. Al mostrar la respuesta, FoliOS reemplaza localmente cada código por su nombre. Así el usuario ve nombres, pero los nombres nunca salen de la planta.

### Nivel de cada revisión de tara (decisión del diseñador)

- **Neto por tote y tara del envase:** por recepción. Se pueden revisar desde el destare.
- **Histórico por CSG:** por lote de materia prima (`lotes_materia_prima`, que ya tiene CSG, envases y kilos netos). Una recepción con varios CSG se revisa lote por lote, así que la revisión queda disponible una vez que Validación MP creó los lotes.
- **Tara del envase:** el backend ya rechaza en recepciones nuevas las taras de bins o totes distintas de la oficial. Este chequeo sirve sobre todo para correcciones y recepciones antiguas.
- **Esponjas:** las esponjas no tienen identidad individual. La «historia» se mide por tipo de esponja (`esponjas`, `esponja_tote`) en la temporada activa, en toda la planta.

### Permisos del módulo (decisión del diseñador)

- **Usar el asistente:** módulo habilitado por perfil.
- **Configurar** modos, presupuesto, límites y rangos de tara: permiso de administración del asistente, que se asigna por perfil igual que los demás.
- **Registro de preguntas:** lo ve quien tiene ese permiso de administración. Las preguntas de RRHH o Finanzas solo se muestran a quien, además, tiene el permiso de consulta de ese dominio.
- **Alcance de cada consulta:** se controla con los permisos de módulo de la oficina de origen. `AlcanceOperacionalUsuario` se suma donde define un alcance, por ejemplo en cámaras.

### Modos

Un ajuste global en Administración, con tres valores:

- **Sin IA:** el valor predeterminado.
- **Mixto:** primero intentan las reglas. Si no reconocen la pregunta con suficiente confianza, la pregunta pasa a Haiku.
- **Haiku:** todas las preguntas van a Haiku.

Si Haiku no está disponible (sin red, tope alcanzado o error), el asistente vuelve al modo sin IA y lo dice.

### Tope de gasto

Tres barreras independientes:

1. Límite de gasto mensual configurado en la cuenta de Anthropic.
2. Presupuesto mensual en FoliOS: se suma el costo de cada llamada según su `usage` y, al alcanzarlo, se pasa a modo sin IA hasta el mes siguiente.
3. Límite diario de preguntas a Haiku por usuario.

Los tres valores son configurables en Administración y muestran el consumo del mes.

**Costo estimado, no medido:** del orden de US$0,002 a 0,005 por pregunta con Haiku 5.5, que cuesta US$0,10 por millón de tokens de entrada y US$0,50 por millón de salida.

### Registro

- Cada pregunta queda guardada, sin borrado físico, con:
  - usuario;
  - texto;
  - modo e intérprete que respondió;
  - consulta usada;
  - si se respondió o no;
  - tokens y costo, cuando respondió Haiku.
- Una vista en Administración muestra las preguntas no respondidas, para ampliar el catálogo.
- El texto de las preguntas de RRHH o Finanzas se guarda igual, porque no sale de la planta.

### Interfaz: el gajo asistente

**Cerrado:** un gajo flotante, la hoja del logo, en una esquina, del color del área de la pantalla abierta. Se ubica donde no tapa la acción principal ni la tabla. El usuario puede arrastrarlo a otra esquina y la posición se recuerda por usuario. No se mueve solo ni avisa por iniciativa propia.

**Abierto:** se despliega en un panel lateral con forma de etiqueta Gajos vivos:

- historial de la conversación;
- campo de pregunta;
- respuestas con códigos en mono, estados con glifo y cantidades en celdas;
- un enlace a la oficina donde se trabaja cada dato.

Indica en qué modo respondió («Sin IA» o «Haiku») y, cuando no entendió, muestra las sugerencias.

**Atajo de teclado:** abre y cierra el panel.

**Relación con Gajos vivos:** el gajo asistente es un control, no un adorno. La guarda de CI contra gajos decorativos del plan de adopción debe admitirlo.

```text
                                        ┌───────────────────────────┐
  [pantalla de la oficina]              │ ◆ ASISTENTE · Sin IA      │
                                        │ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ │
                                        │ › revisa las taras de hoy │
                                        │ 3 recepciones para revisar│
                                        │ REC-2611-0108  7,9 kg/tote│
                                        │ ◆ bajo el rango 8,2–9,5   │
                                        │ [Abrir en Romana]         │
                                        │ ┌───────────────────────┐ │
                                   (◢)  │ │ Pregunta…             │ │
                                        └───────────────────────────┘
```

## Lo que queda para quien lo construya

- La lista exacta de consultas del primer corte. Debe cubrir todos los temas de la tabla «Qué debe responder», empezando por tiempos de Romana, kilos, kilos por CSG y la revisión de tara.
- El algoritmo de puntaje del intérprete sin IA y su umbral de confianza para el modo mixto.
- El nombre del módulo de acceso, las rutas y las tablas.
- El atajo de teclado.

## Decisiones abiertas

Ninguna. El 22-11-2026 el usuario confirmó que «formatos» es el formato de embalaje y que el margen de las esponjas se calcula desde la historia.
