import { officeEntryHref } from './shared/folios-entry';

const byId = (id) => document.getElementById(id);
const form = byId('receptionCsgFilters');
const fields = byId('receptionCsgFields');
const result = byId('receptionCsgResult');
const status = byId('receptionCsgStatus');
const warning = byId('receptionCsgError');
const download = byId('receptionCsgDownload');
const previous = byId('receptionCsgPrevious');
const next = byId('receptionCsgNext');
const apiRoot = '/api/materia-prima/recepciones-csg';
const tokenKey = 'estiba_wms_office_token';
const kilograms = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
const escape = (value) => String(value ?? '—').replaceAll('&', '&amp;').replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const date = (value) => value.replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3-$2-$1');
const weight = (value) => kilograms.format(value);
let activeReport = null;
let working = false;

async function request(path, accept = 'application/json') {
    const token = localStorage.getItem(tokenKey);
    if (!token) {
        window.location.replace(officeEntryHref(window.location));
        throw new Error('Inicia sesión para consultar el informe.');
    }
    const response = await fetch(path, { headers: { Accept: accept, Authorization: `Bearer ${token}` } });
    if (response.ok) return response;

    if (response.status === 401) {
        localStorage.removeItem(tokenKey);
        localStorage.removeItem('estiba_wms_office_identity');
        window.location.replace(officeEntryHref(window.location));
    }
    const error = await response.json().catch(() => ({}));
    const message = Object.values(error.errors ?? {}).flat()[0] ?? error.message;
    throw new Error(response.status === 403 ? 'Tu perfil no tiene acceso al informe de recepción.' : message || 'No se pudo completar la consulta.');
}

function showError(message = '') {
    warning.textContent = message;
    warning.hidden = message === '';
}

function render(report) {
    const { cliente, temporada, periodo, totales, resumen, grafico, detalle, paginacion } = report;
    byId('receptionCsgClient').textContent = cliente.nombre;
    const range = periodo.desde === periodo.hasta ? date(periodo.desde) : `${date(periodo.desde)} al ${date(periodo.hasta)}`;
    byId('receptionCsgPeriod').textContent = `${range} · Temporada ${temporada.codigo}`;

    byId('receptionCsgTotals').innerHTML = [
        ['Kilos netos Romana', `${weight(totales.kilos_netos)} kg`],
        ['Kilos con CSG', `${weight(totales.kilos_con_csg)} kg`],
        ['Recepciones cerradas', weight(totales.recepciones)],
        ['CSG con recepción', weight(totales.csg)],
    ].map(([label, value]) => `<div class="eui-panel"><dt>${label}</dt><dd>${value}</dd></div>`).join('');

    const balance = byId('receptionCsgBalance');
    balance.innerHTML = [
        totales.kilos_pendientes > 0 ? `<p><strong>${weight(totales.kilos_pendientes)} kg</strong> pendientes de CSG.</p>` : '',
        totales.kilos_excedentes > 0 ? `<p><strong>${weight(totales.kilos_excedentes)} kg</strong> asignados por sobre el neto de Romana.</p>` : '',
    ].join('');
    balance.hidden = balance.innerHTML === '';

    byId('receptionCsgSummary').innerHTML = resumen.map((row) => `<tr>
        <td><strong>${escape(row.csg)}</strong></td><td>${escape(row.productor)}</td>
        <td class="eui-numeric">${weight(row.lotes)}</td><td class="eui-numeric">${weight(row.kilos)}</td>
    </tr>`).join('') || '<tr><td colspan="4">Sin kilos atribuidos a CSG en el período.</td></tr>';

    byId('receptionCsgChartTitle').textContent = grafico.length < resumen.length
        ? `${grafico.length} CSG con más kilos` : 'Kilos por CSG';
    const highest = grafico[0]?.kilos || 1;
    byId('receptionCsgChart').innerHTML = grafico.map((row) => `<li>
        <span>${escape(row.csg)}</span><span class="report-csg__bar" style="--reception-csg-bar: ${100 * row.kilos / highest}%" aria-hidden="true"></span>
        <strong>${weight(row.kilos)} kg</strong>
    </li>`).join('') || '<li class="report-csg__chart-empty">Sin datos para graficar.</li>';

    byId('receptionCsgDetail').innerHTML = detalle.map((row) => `<tr${row.csg === null ? ' data-reconciliation' : ''}>
        <td><time datetime="${escape(row.fecha)}">${escape(date(row.fecha))}</time></td>
        <td><strong>${escape(row.recepcion)}</strong></td><td>${escape(row.guia)}</td>
        <td>${escape(row.lote)}</td><td>${escape(row.rotulo_csg)}</td><td>${escape(row.variedad)}</td>
        <td class="eui-numeric">${weight(row.kilos)}</td>
    </tr>`).join('') || `<tr><td colspan="7">${totales.recepciones === 0
        ? 'No hay recepciones cerradas para el cliente y período seleccionados.' : 'No hay filas en esta página.'}</td></tr>`;

    byId('receptionCsgPager').hidden = paginacion.paginas < 2;
    byId('receptionCsgPage').textContent = `Página ${paginacion.pagina} de ${paginacion.paginas}`;
    result.hidden = false;
}

function setBusy(busy) {
    working = busy;
    fields.disabled = busy;
    result.setAttribute('aria-busy', String(busy));
    download.disabled = busy || !activeReport;
    previous.disabled = busy || !activeReport || activeReport.page <= 1;
    next.disabled = busy || !activeReport || activeReport.page >= activeReport.pages;
}

async function consult(path, params, page) {
    if (working) return;
    showError();
    setBusy(true);
    status.textContent = 'Consultando recepciones…';
    try {
        const query = new URLSearchParams(params);
        query.set('page', page);
        const response = await request(`${path}?${query}`);
        const { data } = await response.json();
        activeReport = { path, params, page: data.paginacion.pagina, pages: data.paginacion.paginas };
        render(data);
    } catch (error) {
        showError(error.message);
    } finally {
        status.textContent = '';
        setBusy(false);
    }
}

form.addEventListener('input', () => {
    activeReport = null;
    result.hidden = true;
    showError();
});
form.addEventListener('submit', (event) => {
    event.preventDefault();
    const values = new FormData(form);
    const client = encodeURIComponent(values.get('cliente'));
    const params = new URLSearchParams({ desde: values.get('desde') });
    if (values.get('hasta')) params.set('hasta', values.get('hasta'));
    void consult(`${apiRoot}/${client}`, params, 1);
});
previous.addEventListener('click', () => {
    if (activeReport) void consult(activeReport.path, activeReport.params, activeReport.page - 1);
});
next.addEventListener('click', () => {
    if (activeReport) void consult(activeReport.path, activeReport.params, activeReport.page + 1);
});
download.addEventListener('click', async () => {
    if (working || !activeReport) return;
    showError();
    setBusy(true);
    try {
        const response = await request(`${activeReport.path}/pdf?${activeReport.params}`, 'application/pdf');
        const url = URL.createObjectURL(await response.blob());
        const name = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/i)?.[1];
        const link = document.createElement('a');
        link.href = url;
        link.download = name || 'informe-recepcion-csg.pdf';
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (error) {
        showError(error.message);
    } finally {
        setBusy(false);
    }
});

async function loadOptions() {
    try {
        const response = await request(`${apiRoot}/opciones`);
        const { data } = await response.json();
        const select = form.elements.cliente;
        select.replaceChildren(new Option('Seleccionar cliente', ''));
        for (const client of data.clientes) {
            select.add(new Option(`${client.codigo} · ${client.nombre}`, client.id));
        }
        if (data.clientes.length === 1) select.value = data.clientes[0].id;
        form.elements.desde.value = data.hoy;
        fields.disabled = data.clientes.length === 0;
        status.textContent = data.clientes.length > 0 ? '' : 'No hay clientes con recepciones cerradas en la temporada activa.';
    } catch (error) {
        status.textContent = '';
        showError(error.message);
    }
}

if (localStorage.getItem(tokenKey)) void loadOptions();
