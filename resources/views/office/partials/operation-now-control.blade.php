<section class="operation-now-tabpanel" role="tabpanel" id="operationPanel-resumen" aria-labelledby="operationTab-resumen" data-operation-panel="resumen" tabindex="0">
    <div class="operation-now-overview">
        <x-folios.atencion />
        <div class="operation-now-overview__metrics" aria-label="Indicadores de planta">
            @foreach ([
                ['recepcion-mp', 'materia-prima', 'MP', 'Recepción MP', 'operationOverviewMp', 'operationOverviewMpDetail', 'Camiones con fruta en curso'],
                ['validacion-pt', 'frigorifico', 'FR', 'Validación PT', 'operationOverviewPt', null, 'Validaciones registradas en la jornada'],
                ['camaras', 'frigorifico', 'FR', 'Cámaras PT', 'operationOverviewCold', 'operationOverviewColdDetail', 'Ocupación de cámaras PT'],
                ['despacho', 'frigorifico', 'FR', 'Despacho PT', 'operationOverviewDispatch', 'operationOverviewDispatchDetail', 'Cargas activas'],
            ] as [$tab, $area, $initials, $label, $valueId, $detailId, $detail])
                <a class="folios-ticket operation-now-overview__ticket" data-ticket-area="{{ $area }}" href="#{{ $tab }}" data-operation-jump="{{ $tab }}">
                    <span class="folios-ticket__stub" aria-hidden="true"><x-folios.gajo :area="$area" :size="22" />{{ $initials }}</span>
                    <span class="operation-now-overview__metric">
                        <span>{{ $label }}</span>
                        <strong id="{{ $valueId }}">—</strong>
                        <small @if ($detailId) id="{{ $detailId }}" @endif>{{ $detail }}</small>
                        @if ($detailId)
                            <span class="operation-now-overview__visual" data-overview-visual="{{ $tab }}" aria-hidden="true" hidden></span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
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
