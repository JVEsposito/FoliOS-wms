const $ = (id) => document.getElementById(id);
const cantidad = new Intl.NumberFormat('es-CL');
const fechaHora = new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Santiago' });
const fecha = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value) ? value.split('-').reverse().join('-') : value;

function descargar(blob, nombre) {
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(blob);
    enlace.download = nombre;
    document.body.append(enlace);
    enlace.click();
    enlace.remove();
    setTimeout(() => URL.revokeObjectURL(enlace.href), 60000);
}

async function ejecutar(control, status, accion) {
    control.disabled = true;
    try {
        await accion();
    } catch (error) {
        $(status).textContent = error.message;
    } finally {
        control.disabled = false;
    }
}

function descripcionLote(api, esc) {
    const importar = $('scLotUpload');
    const editar = $('scLotPdf');
    let archivo;
    let sif;
    importar.elements.archivo.addEventListener('change', () => {
        archivo = null;
        editar.hidden = true;
        $('scLotStatus').textContent = '';
    });
    importar.addEventListener('submit', (event) => {
        event.preventDefault();
        const carga = new FormData(importar);
        const seleccionado = importar.elements.archivo.files[0];
        ejecutar(importar.querySelector('button'), 'scLotStatus', async () => {
            $('scLotStatus').textContent = 'Leyendo pallets y cajas…';
            const lote = await api('/lote', { method: 'POST', body: carga });
            if (importar.elements.archivo.files[0] !== seleccionado) return;
            archivo = seleccionado;
            sif = lote.sif;
            editar.reset();
            $('scLotPallets').innerHTML = lote.pallets.map((p) => `<tr data-folio="${esc(p.folio)}">
                <th scope="row">${esc(p.folio)}</th><td>${cantidad.format(p.cajas)}</td><td>${cantidad.format(p.acumulado)}</td>
                <td><input data-cert="premuestreo" maxlength="60" aria-label="Certificado de premuestreo · ${esc(p.folio)}"></td>
                <td><input data-cert="cajas_premuestreo" type="number" min="1" max="${p.cajas}" step="1" aria-label="Cajas de premuestreo · ${esc(p.folio)}"></td>
                <td><input data-cert="inspeccion" maxlength="60" aria-label="Certificado de inspección · ${esc(p.folio)}"></td>
                <td><input data-cert="cajas_inspeccion" type="number" min="1" max="${p.cajas}" step="1" aria-label="Cajas de inspección · ${esc(p.folio)}"></td>
            </tr>`).join('');
            $('scLotStatus').textContent = `SIF ${lote.sif} · Planta ${lote.planta} · Especie ${lote.especie_codigo} · ${fecha(lote.fecha)} · ${lote.pallets.length} pallets · ${cantidad.format(lote.total_cajas)} cajas.`;
            $('scLotPdfStatus').textContent = '';
            editar.hidden = false;
        });
    });
    editar.addEventListener('submit', (event) => {
        event.preventDefault();
        ejecutar(editar.querySelector('button'), 'scLotPdfStatus', async () => {
            if (!archivo) throw new Error('Lee un archivo .INS para generar la planilla.');
            const datos = new FormData(editar);
            const certificados = [];
            for (const fila of $('scLotPallets').rows) {
                const campos = [...fila.querySelectorAll('[data-cert]')].filter((input) => input.value.trim() !== '');
                if (campos.length) certificados.push({ folio: fila.dataset.folio, ...Object.fromEntries(campos.map((i) => [i.dataset.cert, i.value.trim()])) });
            }
            datos.set('certificados', JSON.stringify(certificados));
            datos.set('archivo', archivo);
            const nombre = `Descripcion-lote-${sif}.pdf`;
            $('scLotPdfStatus').textContent = 'Generando PDF…';
            descargar(await api('/lote/pdf', { method: 'POST', body: datos }, true), nombre);
            $('scLotPdfStatus').textContent = 'Planilla descargada.';
        });
    });
}

function mexico(api, esc) {
    const form = $('scMexicoForm');
    const estado = $('scMexicoStatus');
    const reanudar = $('scMexicoResume');
    const resultado = $('scMexicoResult');
    const inicio = form.querySelector('button');
    const nombres = { encontrado: 'Registrado', no_encontrado: 'Sin registro', no_verificable: 'No verificable', sin_codigo: 'Sin código válido' };
    let revision;
    let grupo;
    let detalle;

    function mostrarFilas() {
        $('scMexicoRows').innerHTML = detalle.filas
            .filter((fila) => !$('scMexicoOnlyIssues').checked || fila.observaciones.length > 0)
            .map((fila) => {
                const sag = fila.registro_sag.map((r) => `${r.especie} ${r.variedad} ${r.clasificacion}`.trim()).join(' · ');
                const notas = [...fila.observaciones, ...(fila.observaciones.length && sag ? [`SAG informa ${sag}.`] : [])].join(' ');
                return `<tr><td>${fila.fila}</td><td>${esc(fila.folio || '—')}</td><td>${esc(fila.sdp || fila.sdp_original || '—')}</td>
                    <td>${esc(fecha(fila.fecha) || '—')}</td><td>${cantidad.format(fila.cajas)}</td><td>${esc(nombres[fila.estado])}</td>
                    <td>${esc(fila.clasificaciones.join(', ') || '—')}</td><td class="sc-tool-note">${esc(notas || '—')}</td></tr>`;
            }).join('') || '<tr><td colspan="8">Sin observaciones.</td></tr>';
    }

    async function verificar() {
        reanudar.hidden = true;
        while (grupo < revision.grupos) {
            estado.textContent = `Consultando ${revision.codigos} códigos en SAG · grupo ${grupo + 1} de ${revision.grupos}…`;
            await api(`/mexico/${revision.id}/grupos/${grupo}`, { method: 'POST' });
            grupo += 1;
        }
        detalle = await api(`/mexico/${revision.id}`);
        $('scMexicoDates').innerHTML = detalle.fechas.map((r) => `<tr><td>${esc(fecha(r.fecha))}</td><td>${cantidad.format(r.cajas)}</td></tr>`).join('');
        $('scMexicoOnlyIssues').checked = detalle.filas_con_observaciones > 0;
        mostrarFilas();
        resultado.hidden = false;
        const consulta = detalle.consulta_hasta ? ` Última consulta al SAG el ${fechaHora.format(new Date(detalle.consulta_hasta))}.` : '';
        estado.textContent = `${cantidad.format(detalle.total_cajas)} cajas · ${detalle.filas.length} líneas · ${detalle.filas_con_observaciones} con observaciones.${consulta}`;
    }

    async function consultar(carga) {
        form.elements.archivo.disabled = true;
        try {
            if (carga) {
                revision = null;
                grupo = 0;
                resultado.hidden = true;
                reanudar.hidden = true;
                estado.textContent = 'Leyendo el packing list…';
                revision = await api('/mexico', { method: 'POST', body: carga });
            }
            await verificar();
        } catch (error) {
            reanudar.hidden = !revision;
            throw error;
        } finally {
            form.elements.archivo.disabled = false;
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const carga = new FormData(form);
        ejecutar(inicio, 'scMexicoStatus', () => consultar(carga));
    });
    reanudar.addEventListener('click', () => ejecutar(inicio, 'scMexicoStatus', () => consultar()));
    $('scMexicoOnlyIssues').addEventListener('change', mostrarFilas);
    $('scMexicoPdf').addEventListener('click', (event) => {
        ejecutar(event.currentTarget, 'scMexicoStatus', async () => {
            descargar(await api(`/mexico/${revision.id}/pdf`, {}, true), 'Revision-SdP-Mexico.pdf');
        });
    });
}

function china(api, esc) {
    const form = $('scChinaForm');
    const estado = $('scChinaStatus');
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const body = JSON.stringify(Object.fromEntries(new FormData(form)));
        ejecutar(form.querySelector('button'), 'scChinaStatus', async () => {
            $('scChinaResult').hidden = true;
            estado.textContent = 'Consultando el registro SAG de cerezas…';
            const registros = await api('/china', { method: 'POST', body });
            $('scChinaRows').innerHTML = registros.resultados.map((r) => {
                const ubicacion = r.ubicaciones.map((u) => [u.region, u.comuna].filter(Boolean).join(' · ')).join(' / ');
                return `<tr><td>${esc(r.tipo)}</td><td>${esc(r.codigo)}</td><td>${r.inscrito ? 'Inscrito' : 'Sin registro'}</td><td>${esc(ubicacion || '—')}</td></tr>`;
            }).join('');
            const publicado = registros.actualizado ? ` · Publicado el ${fecha(registros.actualizado)}` : '';
            estado.textContent = `${registros.resultados.length} códigos consultados · ${registros.archivo}${publicado}.`;
            $('scChinaResult').hidden = false;
        });
    });
}

export function initSagTools(sagApi, esc) {
    const botones = document.querySelectorAll('[data-sag-tool]');
    botones.forEach((boton) => boton.addEventListener('click', () => {
        botones.forEach((b) => b.setAttribute('aria-pressed', String(b === boton)));
        document.querySelectorAll('[data-sag-tool-panel]').forEach((panel) => { panel.hidden = panel.dataset.sagToolPanel !== boton.dataset.sagTool; });
    }));
    const api = (path, options, pdf) => sagApi(`/herramientas${path}`, options, pdf);
    descripcionLote(api, esc);
    mexico(api, esc);
    china(api, esc);
}
