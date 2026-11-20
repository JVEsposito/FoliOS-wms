<section class="operation-now-tabpanel" role="tabpanel" id="operationPanel-resumen" aria-labelledby="operationTab-resumen" data-operation-panel="resumen" tabindex="0">
    <div class="operation-now-overview" aria-label="Indicadores de planta">
        <a class="operation-now-overview__card" href="#recepcion-mp" data-operation-jump="recepcion-mp">
            <x-folios.gajo area="materia-prima" :size="112" class="operation-now-overview__gajo" />
            <span>Recepción MP</span><strong id="operationOverviewMp">—</strong><small id="operationOverviewMpDetail">Camiones con fruta en curso</small>
        </a>
        <a class="operation-now-overview__card" href="#validacion-pt" data-operation-jump="validacion-pt">
            <x-folios.gajo area="frigorifico" :size="112" class="operation-now-overview__gajo" />
            <span>Validación PT</span><strong id="operationOverviewPt">—</strong><small>Validaciones registradas en la jornada</small>
        </a>
        <a class="operation-now-overview__card" href="#camaras" data-operation-jump="camaras">
            <x-folios.gajo area="frigorifico" :size="112" class="operation-now-overview__gajo" />
            <span>Frigorífico</span><strong id="operationOverviewCold">—</strong><small id="operationOverviewColdDetail">Ocupación de cámaras PT</small>
        </a>
        <a class="operation-now-overview__card" href="#despacho" data-operation-jump="despacho">
            <x-folios.gajo area="frigorifico" :size="112" class="operation-now-overview__gajo" />
            <span>Despacho PT</span><strong id="operationOverviewDispatch">—</strong><small id="operationOverviewDispatchDetail">Cargas activas</small>
        </a>
    </div>
    <section class="operation-now-panel" aria-labelledby="operationOverviewReceptionTitle">
        <header class="operation-now-panel__heading">
            <div class="operation-now-panel__title"><x-folios.gajo area="materia-prima" :size="30" /><div><h2 id="operationOverviewReceptionTitle">Camiones con fruta en planta</h2><span>Recepciones abiertas por antigüedad de ingreso</span></div></div>
            <a class="operation-now-action folios-action" href="#recepcion-mp" data-operation-jump="recepcion-mp">Ver recepción MP <span aria-hidden="true">→</span></a>
        </header>
        @include('office.partials.operation-now-reception-table', ['tabla' => 'operationOverviewReceptionRows'])
    </section>
</section>

<section class="operation-now-tabpanel" role="tabpanel" id="operationPanel-recepcion-mp" aria-labelledby="operationTab-recepcion-mp" data-operation-panel="recepcion-mp" tabindex="0" hidden>
    <section class="operation-now-panel" aria-labelledby="operationReceptionTitle">
        <header class="operation-now-panel__heading">
            <div class="operation-now-panel__title">
                <div><h2 id="operationReceptionTitle">Recepción de materia prima</h2><span>Camiones con fruta y kilos netos de recepciones cerradas, por jornada de ingreso</span></div>
            </div>
            <a class="operation-now-action" id="operationOpenRomana" href="/oficina/romana" hidden>Abrir Romana <span aria-hidden="true">→</span></a>
        </header>
        <dl class="operation-now-flow-metrics" aria-label="Etapas de recepción">
            <div><dt>En ingreso</dt><dd id="operationMpEntry">—</dd></div>
            <div><dt>Pesaje de envases</dt><dd id="operationMpWeighing">—</dd></div>
            <div><dt>Pendiente de destare</dt><dd id="operationMpTare">—</dd></div>
            <div><dt>Cerradas en la jornada</dt><dd id="operationMpClosed">—</dd></div>
            <div><dt>Kilos netos de la jornada</dt><dd id="operationMpKilos">—</dd></div>
        </dl>
        <p class="operation-now-flow-caption" id="operationMpQueue">Consultando recepciones abiertas…</p>
        @include('office.partials.operation-now-reception-table', ['tabla' => 'operationReceptionRows'])
    </section>
</section>

<section class="operation-now-tabpanel" role="tabpanel" id="operationPanel-validacion-pt" aria-labelledby="operationTab-validacion-pt" data-operation-panel="validacion-pt" tabindex="0" hidden>
    <section class="operation-now-panel" aria-labelledby="operationValidationTitle">
        <header class="operation-now-panel__heading">
            <div class="operation-now-panel__title"><div><h2 id="operationValidationTitle">Validación de producto terminado</h2><span>Resultados de la jornada según la captura en planta</span></div></div>
            <a class="operation-now-action" id="operationOpenValidation" href="/oficina/validacion" hidden>Abrir Validación PT <span aria-hidden="true">→</span></a>
        </header>
        <dl class="operation-now-flow-metrics" aria-label="Resultados de validación PT">
            <div data-tone="success"><dt>Aprobadas</dt><dd id="operationPtApproved">—</dd></div>
            <div data-tone="warning"><dt>Observadas</dt><dd id="operationPtObserved">—</dd></div>
            <div data-tone="critical"><dt>Rechazadas</dt><dd id="operationPtRejected">—</dd></div>
            <div data-tone="critical"><dt>Conflictos</dt><dd id="operationPtConflicts">—</dd></div>
        </dl>
        <p class="operation-now-flow-caption">Últimos registros sincronizados de la jornada</p>
        <div class="operation-now-table-scroll">
            <table class="operation-now-table operation-now-table--flow">
                <caption class="office-visually-hidden">Validaciones recientes de producto terminado</caption>
                <thead><tr><th scope="col">Folio</th><th scope="col">Línea</th><th scope="col">Turno</th><th scope="col">Sincronización</th><th scope="col">Resultado</th></tr></thead>
                <tbody id="operationValidationRows"><tr><td colspan="5"><div class="operation-now-empty">Consultando validaciones…</div></td></tr></tbody>
            </table>
        </div>
    </section>
</section>
