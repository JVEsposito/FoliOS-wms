<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>FoliOS · Catálogo visual</title>
    <style>{!! $styles !!}</style>
</head>
<body>
<div class="estiba-ui catalog-ui" id="catalog" data-density="comfortable">
    <a href="#contenido" class="catalog-skip">Saltar al contenido</a>
    <header class="catalog-bar">
        <a href="#contenido" class="catalog-brand" aria-label="FoliOS, inicio del catálogo">
            <x-folios-logo surface="dark" alt="FoliOS" :inline="true" />
        </a>
        <span class="catalog-bar__context">SISTEMA VISUAL<br><strong>Operación · Oficina · Tablet</strong></span>
        <button type="button" class="catalog-theme-toggle" id="catalogThemeToggle" aria-pressed="false">Modo oscuro</button>
        <span class="catalog-bar__version">Catálogo de componentes <strong>01</strong></span>
    </header>
    <div class="catalog-layout">
        <aside class="catalog-sidebar">
            <p class="catalog-nav-label">FUNDACIONES</p>
            <nav aria-label="Secciones del catálogo">
                <a href="#areas"><span>00</span> Áreas del gajo</a>
                <a href="#identidad"><span>01</span> Identidad</a>
                <a href="#lectura"><span>02</span> Lectura operacional</a>
                <a href="#acciones"><span>03</span> Acciones y entradas</a>
                <a href="#excepciones"><span>04</span> Estados y mensajes</a>
                <a href="#tactil"><span>05</span> Uso en tablet</a>
                <a href="#iconografia"><span>06</span> Iconografía</a>
            </nav>
            <p class="catalog-sidebar__note">Cada panel debe ayudar a consultar, decidir o actuar.</p>
        </aside>
        <main id="contenido" class="catalog-main" tabindex="-1">
            <x-estiba.heading title="Fundaciones visuales" eyebrow="FoliOS OPERACIONAL" description="Una misma lectura para Oficina, tablet y PDA." />
            <p class="catalog-disclosure">Ejemplos ficticios · Este catálogo no consulta ni modifica datos de planta.</p>

            <section id="areas" class="catalog-section" aria-labelledby="areasTitle">
                <div class="catalog-section-heading"><span>00</span><h2 id="areasTitle">Áreas del gajo</h2></div>
                <p class="catalog-note">Cada hoja del símbolo es un área. Su color marca el menú, la etiqueta sobre el título y la regla de la barra de contexto. Los estados nunca usan el color de un área.</p>
                <div class="catalog-areas">
                    @foreach(['materia-prima' => 'Materia Prima', 'frigorifico' => 'Frigorífico', 'materiales' => 'Materiales', 'consultas' => 'Consultas', 'gerencia' => 'Gerencia', 'administracion' => 'Administración', 'operacion' => 'Operación ahora'] as $area => $label)
                        <div class="catalog-area" data-catalog-area="{{ $area }}">
                            <span class="catalog-area__mark"><x-folios.gajo :area="$area" :size="40" /></span>
                            <x-folios.area-tag :area="$area">{{ $label }}</x-folios.area-tag>
                        </div>
                    @endforeach
                </div>
            </section>

            <section id="identidad" class="catalog-section" aria-labelledby="identityTitle">
                <div class="catalog-section-heading"><span>01</span><h2 id="identityTitle">Identidad y significado</h2></div>
                <div class="catalog-swatches">
                    @foreach(['canvas' => 'Papel', 'surface' => 'Etiqueta', 'text' => 'Tinta', 'navy' => 'Navy', 'frost' => 'Frost: marca y acción', 'seal' => 'Sello'] as $key => $label)
                        <div class="catalog-swatch"><span style="background: {{ $tokens['color'][$key] }}"></span><strong>{{ $label }}</strong><code>{{ $tokens['color'][$key] }}</code></div>
                    @endforeach
                </div>
                <div class="catalog-signals">
                    <x-estiba.signal tone="neutral">Sin registro</x-estiba.signal>
                    <x-estiba.signal tone="info">En preparación</x-estiba.signal>
                    <x-estiba.signal tone="success">Confirmado</x-estiba.signal>
                    <x-estiba.signal tone="warning">Requiere atención</x-estiba.signal>
                    <x-estiba.signal tone="reserved">Reservado</x-estiba.signal>
                    <x-estiba.signal tone="temporary">Extraído temporalmente</x-estiba.signal>
                    <x-estiba.signal tone="blocked">Bloqueado</x-estiba.signal>
                    <x-estiba.signal tone="critical">Emergencia</x-estiba.signal>
                </div>
                <p class="catalog-note">El color acompaña al estado escrito. Rojo identifica una condición crítica o bloqueo; no significa simplemente “ocupado”.</p>
            </section>

            <section id="lectura" class="catalog-section" aria-labelledby="readingTitle">
                <div class="catalog-section-heading"><span>02</span><h2 id="readingTitle">Lectura operacional</h2></div>
                <fieldset class="catalog-density"><legend>Densidad de los controles</legend><div class="eui-actions">
                    <button class="eui-button eui-button--secondary" type="button" data-density-choice="comfortable" aria-pressed="true">Normal</button>
                    <button class="eui-button eui-button--secondary" type="button" data-density-choice="compact" aria-pressed="false">Compacta</button>
                    <button class="eui-button eui-button--secondary" type="button" data-density-choice="touch" aria-pressed="false">Táctil</button>
                </div></fieldset>
                <div class="catalog-reading">
                    <x-estiba.panel title="Folios de una carga" :flush="true">
                        <x-slot:actions><x-estiba.signal tone="info">Ejemplo</x-estiba.signal></x-slot:actions>
                        <x-estiba.table caption="4 registros ficticios · Folio, posición y condición legibles de un vistazo">
                            <x-slot:head><tr><th scope="col">Folio</th><th scope="col">Ubicación</th><th scope="col">Condición</th></tr></x-slot:head>
                            <tr><td><x-estiba.code>FOL-EJ-001</x-estiba.code></td><td><x-estiba.code>C3 / B08 / P04</x-estiba.code></td><td><x-estiba.signal tone="reserved">Reservado</x-estiba.signal></td></tr>
                            <tr><td><x-estiba.code>FOL-EJ-002</x-estiba.code></td><td>Bajo maniobra</td><td><x-estiba.signal tone="temporary">Extraído temporalmente</x-estiba.signal></td></tr>
                            <tr><td><x-estiba.code>FOL-EJ-003</x-estiba.code></td><td><x-estiba.code>C3 / B02 / P01</x-estiba.code></td><td><x-estiba.signal tone="blocked">Retenido</x-estiba.signal></td></tr>
                            <tr><td><x-estiba.code>FOL-EJ-004</x-estiba.code></td><td>Andén 2</td><td><x-estiba.signal tone="success">Confirmado en andén</x-estiba.signal></td></tr>
                        </x-estiba.table>
                    </x-estiba.panel>
                    <x-estiba.panel title="Avance por objetivo">
                        <div class="catalog-stack">
                            <x-estiba.metric label="Concentración" :value="17" :total="20" tone="success" detail="85% · Objetivo ilustrativo: 80%" />
                            <x-estiba.metric label="Separación" :value="14" :total="20" detail="70% · Objetivo ilustrativo: 100%" />
                            <x-estiba.metric label="Despacho" :value="0" :total="20" detail="0% · Un cero es un dato válido." />
                        </div>
                    </x-estiba.panel>
                </div>
            </section>

            <section id="acciones" class="catalog-section" aria-labelledby="actionsTitle">
                <div class="catalog-section-heading"><span>03</span><h2 id="actionsTitle">Acciones y entradas</h2></div>
                <div class="catalog-two-columns">
                    <x-estiba.panel title="Una acción principal por decisión">
                        <div class="catalog-stack">
                            <div class="eui-actions">
                                <x-estiba.button data-example-action="Consultar folios" icon="arrow-right">Consultar folios</x-estiba.button>
                                <x-estiba.button variant="secondary" data-example-action="Volver al listado">Volver al listado</x-estiba.button>
                            </div>
                            <div class="eui-actions">
                                <x-estiba.button variant="confirm" data-example-action="Confirmar pallet ubicado" icon="check">Confirmar pallet ubicado</x-estiba.button>
                                <x-estiba.button variant="critical" data-example-action="Cancelar maniobra">Cancelar maniobra</x-estiba.button>
                            </div>
                            <div class="eui-actions">
                                <x-estiba.button :disabled="true">Sin permiso para resolver</x-estiba.button>
                                <x-estiba.button :busy="true">Guardando…</x-estiba.button>
                            </div>
                            <p class="catalog-note">Verde confirma un hecho físico. El botón rojo requiere el flujo de confirmación y los permisos de la operación que lo utilice.</p>
                        </div>
                    </x-estiba.panel>
                    <x-estiba.panel title="Etiqueta, ayuda y error junto al campo">
                        <form class="catalog-stack" data-demo-form novalidate>
                            <x-estiba.field id="sampleFolio" name="folio" label="Folio de ejemplo" value="FOL-EJ-001" readonly hint="Identificador completo; nunca se recorta con puntos suspensivos." />
                            <x-estiba.field id="sampleReason" name="motivo" label="Motivo de la revisión" required maxlength="160" hint="Escribe al menos 3 caracteres para probar la validación local." />
                            <span class="eui-field__error" id="sampleReason-error" hidden>Escribe un motivo de al menos 3 caracteres.</span>
                            <x-estiba.button type="submit">Probar validación</x-estiba.button>
                        </form>
                    </x-estiba.panel>
                </div>
                <p id="catalogFeedback" class="catalog-feedback" role="status" aria-live="polite">Los controles de ejemplo solo responden dentro de este catálogo.</p>
            </section>

            <section id="excepciones" class="catalog-section" aria-labelledby="exceptionsTitle">
                <div class="catalog-section-heading"><span>04</span><h2 id="exceptionsTitle">Ausencia de datos y excepciones</h2></div>
                <div class="catalog-two-columns">
                    <x-estiba.panel title="Lo desconocido queda explícito">
                        <x-estiba.metric label="Temperatura ambiente" detail="Sin registro manual. No se infiere temperatura ni estado térmico." />
                        <x-estiba.empty title="No hay discrepancias abiertas" description="Este estado solo corresponde después de recibir una respuesta válida del servidor." />
                    </x-estiba.panel>
                    <div class="catalog-stack">
                        <x-estiba.alert title="No se pudo actualizar" tone="warning">Se conserva la información anterior. Una conexión fallida no equivale a una bandeja vacía.</x-estiba.alert>
                        <x-estiba.alert title="Maniobra pausada por discrepancia" tone="blocked">La resolución de supervisión es necesaria para continuar. La custodia temporal permanece visible.</x-estiba.alert>
                        <x-estiba.alert title="Cargando información" tone="info">Este mensaje indica una consulta en curso; no confirma sincronización.</x-estiba.alert>
                    </div>
                </div>
            </section>

            <section id="tactil" class="catalog-section" aria-labelledby="touchTitle">
                <div class="catalog-section-heading"><span>05</span><h2 id="touchTitle">La misma identidad en tablet</h2></div>
                <div class="catalog-touch-layout">
                    <div class="estiba-ui catalog-touch" data-density="touch">
                        <div class="catalog-touch__bar"><x-folios-logo surface="dark" alt="FoliOS" :inline="true" /><span>REFERENCIA TÁCTIL</span></div>
                        <div class="catalog-touch__body">
                            <x-estiba.heading title="Mi maniobra" level="h2" eyebrow="PASO 2 DE 3 · EJEMPLO" />
                            <x-estiba.signal tone="info">En ejecución</x-estiba.signal>
                            <div class="catalog-instruction"><span>UBICAR PALLET</span><x-estiba.code :large="true">FOL-EJ-001</x-estiba.code><span>Cámara C3 · Banda B08 · Posición P04</span></div>
                            <x-estiba.button variant="confirm" data-example-action="Confirmar pallet ubicado">Confirmar pallet ubicado</x-estiba.button>
                            <x-estiba.button variant="secondary" data-example-action="No coincide">No coincide</x-estiba.button>
                            <x-estiba.alert title="Bajo maniobra" tone="temporary"><x-estiba.code>FOL-EJ-002</x-estiba.code> · Extraído temporalmente. Requiere retorno.</x-estiba.alert>
                        </div>
                    </div>
                    <div class="catalog-stack">
                        <x-estiba.panel title="Secuencia visible, paso actual inequívoco">
                            <x-estiba.progress :steps="[
                                ['label' => 'Extraer FOL-EJ-002', 'state' => 'complete'],
                                ['label' => 'Ubicar FOL-EJ-001', 'state' => 'current'],
                                ['label' => 'Devolver FOL-EJ-002', 'state' => 'return'],
                            ]" />
                        </x-estiba.panel>
                        <p class="catalog-note">Controles táctiles de 56 px como mínimo, tipografía de lectura de 16 px y códigos completos. Los contenidos se reorganizan al reducir el ancho.</p>
                        <p class="catalog-note">Esta muestra compone las piezas visuales. El orden y la disponibilidad de acciones los determina el servicio operacional al integrar cada pantalla.</p>
                    </div>
                </div>
            </section>
            <section id="iconografia" class="catalog-section" aria-labelledby="iconsTitle">
                <div class="catalog-section-heading"><span>06</span><h2 id="iconsTitle">Iconografía</h2></div>
                <p class="catalog-note">Trazo cuadrado (sin puntas ni uniones redondeadas), 20 símbolos con el mismo peso de línea. Los 12 originales se redibujaron; 8 son nuevos para Oficina y Romana.</p>
                <div class="catalog-icons">
                    @foreach([
                        'arrow-right' => 'Continuar', 'check' => 'Confirmado', 'warning' => 'Alerta',
                        'warehouse' => 'Almacén', 'pallet' => 'Pallet', 'snowflake' => 'Cadena de frío',
                        'boxes' => 'Inventario', 'settings' => 'Configuración', 'search' => 'Buscar',
                        'refresh' => 'Actualizar', 'clock' => 'Tiempo', 'lock' => 'Bloqueado',
                        'thermometer' => 'Temperatura', 'scale' => 'Romana', 'truck' => 'Camión',
                        'dock' => 'Andén', 'forklift' => 'Montacargas', 'ticket' => 'Folio',
                        'print' => 'Imprimir', 'filter' => 'Filtrar',
                    ] as $iconName => $label)
                        <div class="catalog-icon"><x-estiba.icon :name="$iconName" /><strong>{{ $label }}</strong><code>{{ $iconName }}</code></div>
                    @endforeach
                </div>
            </section>
            <footer class="catalog-footer">FoliOS · Fundaciones visuales <span>Datos ficticios · Sin operaciones productivas</span></footer>
        </main>
    </div>
</div>
<script>{!! $script !!}</script>
</body>
</html>
