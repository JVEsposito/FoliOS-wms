const $ = (id) => document.getElementById(id);
const escape = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const url = '/api/finanzas/proveedores'; const tokenKey = 'estiba_wms_office_token'; const identityKey = 'estiba_wms_office_identity';
let token = localStorage.getItem(tokenKey); let identity;
try { identity = JSON.parse(localStorage.getItem(identityKey) || 'null'); } catch { identity = null; }
const state = { suppliers: [], accounts: [], types: {}, selected: null, documents: [], manage: false, supplier: null, document: null, action: null, references: [], materials: [], page: 1, documentPage: 1, journalPage: 1, generation: 0 };
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: document.body.dataset.comprasZone || 'America/Santiago', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const money = (v) => { try { return `$${BigInt(v || '0').toLocaleString('es-CL')}`; } catch { return '—'; } };
const table = (heads, rows) => `<table><thead><tr>${heads.map((h) => `<th>${escape(h)}</th>`).join('')}</tr></thead><tbody>${rows || `<tr><td colspan="${heads.length}">Sin registros.</td></tr>`}</tbody></table>`;
const options = (items, empty = '') => (empty ? `<option value="">${escape(empty)}</option>` : '') + items.map(([v, n]) => `<option value="${escape(v)}">${escape(n)}</option>`).join('');
const button = (label, action, id) => `<button type="button" class="secondary-button" data-cp-action="${action}" data-id="${escape(id)}">${escape(label)}</button>`;
const message = (v) => { $('cpMessage').textContent = v; };
const can = (name) => identity?.[name] === true || identity?.capacidades?.[name] === true;
function logout() { localStorage.removeItem(tokenKey); localStorage.removeItem(identityKey); location.reload(); }
async function api(path, method = 'GET', body) {
    let response;
    try { response = await fetch(path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, ...(body ? { body: JSON.stringify(body) } : {}) }); }
    catch { throw new Error('No fue posible conectar. Puede reintentar con los mismos datos.'); }
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) logout();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No fue posible completar la solicitud.');
    return data;
}
async function safely(fn) { try { await fn(); } catch (e) { message(e.message); } }
function open(id) { const d = $(id); const e = d.querySelector('[data-cp-error]'); if (e) e.textContent = ''; d.showModal(); }
function show() {
    if (!can('puede_consultar_proveedores_finanzas')) throw new Error('Su perfil no tiene acceso a Proveedores de Finanzas.');
    $('officeAccess').classList.add('is-hidden'); $('officeApp').classList.remove('is-hidden');
    $('officeUserName').textContent = identity.nombre || 'Usuario'; $('officeUserRole').textContent = identity.rol || ''; $('officeInitials').textContent = (identity.nombre || 'U').split(/\s+/).slice(0, 2).map((s) => s[0]).join('');
}
function tab(name) { for (const b of document.querySelectorAll('[data-cp-tab]')) b.setAttribute('aria-pressed', String(b.dataset.cpTab === name)); for (const p of document.querySelectorAll('[data-cp-panel]')) p.hidden = p.dataset.cpPanel !== name; }
function pages(id, p, load) {
    $(id).replaceChildren(); for (const [label, n] of [['Anterior', p.current_page - 1], ['Siguiente', p.current_page + 1]]) { const b = document.createElement('button'); b.type = 'button'; b.className = 'secondary-button'; b.textContent = label; b.disabled = n < 1 || n > p.last_page; b.onclick = () => safely(() => load(n)); $(id).append(b); } $(id).append(document.createTextNode(`Página ${p.current_page} de ${p.last_page}`));
}
function fill(form, data) { for (const [k, v] of Object.entries(data)) if (form.elements[k] && !['lineas', 'guias'].includes(k)) form.elements[k].value = typeof v === 'boolean' ? String(Number(v)) : (v ?? ''); }
async function loadSuppliers(page = state.page) {
    const generation = ++state.generation;
    const d = await api(`${url}?${new URLSearchParams({ buscar: $('cpSearch').elements.buscar.value, page })}`); if (generation !== state.generation) return;
    state.page = page; state.suppliers = d.data.data; state.accounts = d.cuentas; state.types = d.tipos; state.manage = d.puede_gestionar;
    for (const b of document.querySelectorAll('[data-cp-manage]')) b.hidden = !state.manage;
    if (state.selected) state.selected = state.suppliers.find((p) => p.id === state.selected.id) || state.selected;
    $('cpSuppliers').innerHTML = table(['Proveedor', 'Contacto', 'Control', 'Acciones'], state.suppliers.map((p) => `<tr><td><strong>${escape(p.razon_social)}</strong><br>${escape(p.rut)}<br>${escape(p.categoria)}</td><td>${escape(p.contacto)}<br>${escape(p.email)}<br>${escape(p.telefono)}</td><td>${p.activo ? 'Activo' : 'Inactivo'}<br>${p.pagos_bloqueados ? 'Pagos bloqueados' : 'Pagos habilitados'}<br>${p.proveedor_material_id ? 'Vinculado a Materiales' : 'Ficha financiera'}</td><td>${button('Documentos', 'select', p.id)}${state.manage ? button('Editar ficha', 'supplier', p.id) : ''}${button('Historial', 'supplier-history', p.id)}</td></tr>`).join(''));
    pages('cpSupplierPages', d.data, loadSuppliers); renderAccounts(); message('Directorio actualizado.');
}
function renderAccounts() { $('cpAccounts').innerHTML = table(['Cuenta', 'Tipo', 'Estado', 'Acciones'], state.accounts.map((c) => `<tr><td>${escape(c.codigo)} · ${escape(c.nombre)}</td><td>${escape(c.tipo)}</td><td>${c.activa ? 'Activa' : 'Inactiva'}</td><td>${state.manage ? button('Editar', 'account', c.codigo) : ''}</td></tr>`).join('')); }
async function loadDocuments(page = state.documentPage) {
    if (!state.selected) { $('cpDocuments').textContent = 'Seleccione un proveedor en el directorio.'; return; }
    const selected = state.selected.id; const d = await api(`${url}/documentos?${new URLSearchParams({ proveedor_id: selected, page })}`); if (state.selected.id !== selected) return;
    state.documentPage = page; state.documents = d.data.data; $('cpSelected').textContent = `${state.selected.razon_social} · ${state.selected.rut}`;
    $('cpDocuments').innerHTML = table(['Documento', 'Fechas', 'Total CLP', 'Estado', 'Acciones'], state.documents.map((v) => `<tr><td>${escape(state.types[v.tipo])}<br><strong>${escape(v.folio)}</strong></td><td>Emisión: ${escape(v.fecha_emision)}<br>Vence: ${escape(v.vencimiento)}</td><td>${money(v.total)}</td><td>${v.anulado_at ? 'Anulado internamente' : v.registrado_at ? (v.tipo === '52' ? 'Guía registrada · sin deuda' : 'Contabilizado') : 'Borrador'}</td><td>${button('Ver detalle', 'detail', v.id)}${state.manage && v.anulado_at ? button('Crear corrección', 'correct-document', v.id) : ''}${state.manage && !v.anulado_at ? `${!v.registrado_at ? button('Corregir', 'edit-document', v.id) + button('Registrar', 'post', v.id) : ''}${button(v.registrado_at ? 'Revertir' : 'Anular borrador', 'reverse', v.id)}` : ''}</td></tr>`).join(''));
    pages('cpDocumentPages', d.data, loadDocuments);
}
function journalMarkup(entries) { return entries.map((v) => `<article class="cp-journal-entry"><h3>${escape(v.fecha)} · ${escape(v.glosa)}</h3><p>${v.reversa_de_id ? 'Reverso · ' : ''}${escape(v.user_nombre)}</p><div class="cp-table">${table(['Cuenta', 'Detalle / CECO', 'Debe CLP', 'Haber CLP'], v.lineas.map((l) => `<tr><td>${escape(l.cuenta)} · ${escape(l.cuenta_nombre)}</td><td>${escape(l.descripcion)}<br>${escape(l.ceco)}</td><td>${money(l.debe)}</td><td>${money(l.haber)}</td></tr>`).join(''))}</div></article>`).join('') || '<p>Sin asientos en este período.</p>'; }
async function loadJournal(page = 1) { const q = new URLSearchParams(new FormData($('cpJournalFilters'))); q.set('page', page); const d = await api(`${url}/asientos?${q}`); $('cpJournal').innerHTML = journalMarkup(d.data.data); pages('cpJournalPages', d.data, loadJournal); }
async function materials() { const d = await api(`${url}/materiales?${new URLSearchParams({ buscar: $('cpMaterialSearch').value })}`); state.materials = d.data; const current = $('cpMaterial').value || state.supplier?.proveedor_material_id; $('cpMaterial').innerHTML = options(d.data.map((p) => [p.id, `${p.codigo} · ${p.nombre}`]), 'Sin vínculo con Materiales'); if (current && !d.data.some((p) => p.id === current)) $('cpMaterial').add(new Option('Proveedor vinculado previamente', current)); $('cpMaterial').value = current || ''; }
async function supplierForm(p = null) { state.supplier = p; const f = $('cpSupplierForm'); f.reset(); $('cpSupplierReason').hidden = !p; f.elements.motivo.required = !!p; if (p) fill(f, p); f.elements.rut.readOnly = !!p; $('cpMaterialSearch').value = ''; await materials(); $('cpMaterial').disabled = !!p?.proveedor_material_id; open('cpSupplierDialog'); }
function addLine(line = {}) {
    const row = document.createElement('div'); row.className = 'cp-line';
    row.innerHTML = `<label>Cuenta<select data-line="cuenta" required>${options(state.accounts.filter((c) => c.activa && ['gasto', 'activo'].includes(c.tipo)).map((c) => [c.codigo, `${c.codigo} · ${c.nombre}`]))}</select></label><label>Descripción<input data-line="descripcion" required maxlength="250"></label><label>CECO<input data-line="ceco" maxlength="80"></label><label>Importe CLP<input data-line="monto" inputmode="numeric" pattern="[0-9]{1,15}" required value="0"></label><button class="secondary-button" type="button" data-cp-remove>Quitar</button>`;
    for (const [k, v] of Object.entries(line)) if (row.querySelector(`[data-line="${k}"]`)) row.querySelector(`[data-line="${k}"]`).value = v ?? '';
    $('cpCostLines').append(row);
}
function totals() { const f = $('cpDocumentForm'); try { const base = ['neto', 'exento', 'otros'].reduce((s, k) => s + BigInt(f.elements[k].value || '0'), 0n); const cost = [...$('cpCostLines').querySelectorAll('[data-line="monto"]')].reduce((s, e) => s + BigInt(e.value || '0'), 0n); $('cpTotal').textContent = `Total: ${money(base + BigInt(f.elements.iva.value || '0'))} · Por distribuir: ${money(base - cost)}`; } catch { $('cpTotal').textContent = 'Ingrese importes enteros en CLP.'; } }
async function references() {
    const d = await api(`${url}/referencias?${new URLSearchParams({ proveedor_id: state.selected.id, buscar: $('cpReferenceSearch').value })}`);
    const invoice = $('cpInvoiceReference').value || state.document?.referencia_id || ''; const guides = [...$('cpGuideReferences').selectedOptions].map((o) => o.value); if (!guides.length) guides.push(...(state.document?.guias || []));
    const keep = state.references.filter((v) => v.id === invoice || guides.includes(v.id)); state.references = [...new Map([...keep, ...d.data].map((v) => [v.id, v])).values()];
    $('cpInvoiceReference').innerHTML = options(state.references.filter((v) => ['33', '34'].includes(v.tipo)).map((v) => [v.id, `${state.types[v.tipo]} ${v.folio}`]), 'Sin referencia'); $('cpInvoiceReference').value = invoice;
    $('cpGuideReferences').innerHTML = options(state.references.filter((v) => v.tipo === '52').map((v) => [v.id, `Guía ${v.folio} · ${v.fecha_emision}`])); for (const o of $('cpGuideReferences').options) o.selected = guides.includes(o.value);
}
async function documentForm(d = null) {
    if (!state.selected) throw new Error('Seleccione primero un proveedor.'); if (!state.accounts.length) throw new Error('Instale o configure el plan de cuentas antes de ingresar documentos.');
    state.document = d; state.references = []; const f = $('cpDocumentForm'); f.reset(); delete f.dataset.operation;
    $('cpDocumentType').innerHTML = options(Object.entries(state.types)); fill(f, d || { tipo: '33', fecha_emision: today(), fecha_recepcion: today(), vencimiento: plus30(today()), neto: '0', exento: '0', iva: '0', otros: '0' });
    $('cpCostLines').replaceChildren(); for (const l of (d?.lineas || [{}])) addLine(l);
    $('cpDocumentSupplier').textContent = `${state.selected.razon_social} · ${state.selected.rut}`; $('cpReferenceSearch').value = ''; $('cpInvoiceReference').innerHTML = ''; $('cpGuideReferences').innerHTML = ''; await references(); totals(); open('cpDocumentDialog');
}
function plus30(day) { const d = new Date(`${day}T12:00:00Z`); if (Number.isNaN(d.getTime())) return ''; d.setUTCDate(d.getUTCDate() + 30); return d.toISOString().slice(0, 10); }
async function detail(id) {
    const d = await api(`${url}/documentos/${id}`); const v = d.data;
    $('cpDetail').innerHTML = `<h3>${escape(state.types[v.tipo])} ${escape(v.folio)} · ${money(v.total)}</h3><p>${escape(v.proveedor_snapshot.razon_social)} · ${escape(v.proveedor_snapshot.rut)}</p><p>Respaldo: ${escape(v.respaldo)}</p><p>${escape(v.motivo_anulacion || '')}</p><div class="cp-table">${table(['Cuenta', 'Detalle', 'CECO', 'Costo CLP'], v.lineas.map((l) => `<tr><td>${escape(l.cuenta)} · ${escape(l.cuenta_nombre)}</td><td>${escape(l.descripcion)}</td><td>${escape(l.ceco)}</td><td>${money(l.monto)}</td></tr>`).join(''))}</div>${journalMarkup(d.asientos)}`; open('cpDetailDialog');
}
async function history(entity, id) { const d = await api(`${url}/historial/${entity}/${id}`); $('cpDetail').innerHTML = d.data.data.map((e) => `<p><strong>${escape(e.accion)}</strong> · ${escape(e.user_nombre)} · ${escape(e.created_at)}<br>${escape(e.datos.motivo || '')}</p>`).join(''); open('cpDetailDialog'); }
async function submit(form, work) { const b = form.querySelector('[type="submit"]'); if (b.disabled) return; b.disabled = true; form.querySelector('[data-cp-error]').textContent = ''; try { await work(); form.closest('dialog').close(); } catch (e) { form.querySelector('[data-cp-error]').textContent = e.message; } finally { b.disabled = false; } }
$('officeLoginForm').addEventListener('submit', async (e) => { e.preventDefault(); e.submitter.disabled = true; try { const d = await api('/api/acceso-oficina', 'POST', Object.fromEntries(new FormData(e.target))); token = d.token; identity = d.usuario; localStorage.setItem(tokenKey, token); localStorage.setItem(identityKey, JSON.stringify(identity)); show(); await loadSuppliers(); } catch (err) { $('officeLoginError').textContent = err.message; } finally { e.submitter.disabled = false; } });
$('officeLogoutButton')?.addEventListener('click', logout);
$('cpSearch').addEventListener('submit', (e) => { e.preventDefault(); safely(() => loadSuppliers(1)); });
$('cpJournalFilters').addEventListener('submit', (e) => { e.preventDefault(); safely(() => loadJournal()); });
$('cpNewSupplier').onclick = () => safely(() => supplierForm()); $('cpNewDocument').onclick = () => safely(() => documentForm()); $('cpReloadDocuments').onclick = () => safely(() => loadDocuments(1));
$('cpFindMaterial').onclick = () => safely(materials); $('cpFindReferences').onclick = () => safely(references); $('cpAddLine').onclick = () => { addLine(); totals(); };
$('cpMaterial').onchange = () => { if (!state.supplier) $('cpSupplierForm').elements.razon_social.value = state.materials.find((p) => p.id === $('cpMaterial').value)?.nombre || ''; };
$('cpInitPlan').onclick = () => safely(async () => { $('cpInitPlan').disabled = true; try { await api(`${url}/plan/inicializar`, 'POST', {}); await loadSuppliers(); } finally { $('cpInitPlan').disabled = false; } });
$('cpNewAccount').onclick = () => { state.account = null; $('cpAccountForm').reset(); $('cpAccountForm').elements.codigo.readOnly = false; $('cpAccountForm').elements.tipo.disabled = false; open('cpAccountDialog'); };
$('cpSupplierForm').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => { const data = Object.fromEntries(new FormData(e.target)); data.activo = data.activo === '1'; data.pagos_bloqueados = data.pagos_bloqueados === '1'; data.proveedor_material_id = state.supplier?.proveedor_material_id || data.proveedor_material_id || null; if (state.supplier) data.version = state.supplier.version; const d = await api(state.supplier ? `${url}/fichas/${state.supplier.id}` : url, state.supplier ? 'PUT' : 'POST', data); state.selected = d.data; await loadSuppliers(); message('Ficha guardada.'); }); });
$('cpAccountForm').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => { const data = Object.fromEntries(new FormData(e.target)); data.tipo = state.account?.tipo || data.tipo; data.activa = data.activa === '1'; await api(`${url}/cuentas`, 'POST', data); await loadSuppliers(); }); });
$('cpDocumentForm').addEventListener('input', () => { delete $('cpDocumentForm').dataset.operation; totals(); });
$('cpDocumentForm').elements.fecha_recepcion.addEventListener('change', (e) => { if (!state.document) $('cpDocumentForm').elements.vencimiento.value = plus30(e.target.value); });
$('cpDocumentForm').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => {
    const f = e.target; const data = Object.fromEntries(new FormData(f)); data.proveedor_id = state.selected.id; data.guias = [...$('cpGuideReferences').selectedOptions].map((o) => o.value); data.referencia_id ||= null;
    data.lineas = [...$('cpCostLines').children].map((row) => Object.fromEntries([...row.querySelectorAll('[data-line]')].map((input) => [input.dataset.line, input.value])));
    if (state.document && !state.document.correction) data.version = state.document.version; else { f.dataset.operation ||= crypto.randomUUID(); data.operacion_id = f.dataset.operation; }
    if (state.document?.correction) data.reemplaza_id = state.document.id;
    const editing = state.document && !state.document.correction;
    await api(editing ? `${url}/documentos/${state.document.id}` : `${url}/documentos`, editing ? 'PUT' : 'POST', data); await loadDocuments(1); message('Borrador guardado. Revíselo antes de contabilizar.');
}); });
$('cpActionForm').addEventListener('submit', (e) => { e.preventDefault(); submit(e.target, async () => { const data = Object.fromEntries(new FormData(e.target)); data.version = state.action.doc.version; await api(`${url}/documentos/${state.action.doc.id}/${state.action.action === 'post' ? 'registrar' : 'anular'}`, 'POST', data); await loadDocuments(); message('Registro guardado con trazabilidad.'); }); });
document.addEventListener('click', (e) => {
    const close = e.target.closest('[data-cp-close]'); if (close) close.closest('dialog').close();
    const t = e.target.closest('[data-cp-tab]'); if (t) { tab(t.dataset.cpTab); if (t.dataset.cpTab === 'documentos') safely(() => loadDocuments()); if (t.dataset.cpTab === 'diario') safely(() => loadJournal()); }
    const remove = e.target.closest('[data-cp-remove]'); if (remove) { remove.closest('.cp-line').remove(); totals(); }
    const b = e.target.closest('[data-cp-action]'); if (!b) return;
    safely(async () => {
        const { cpAction: a, id } = b.dataset;
        if (a === 'supplier') return supplierForm(state.suppliers.find((p) => p.id === id));
        if (a === 'select') { state.selected = state.suppliers.find((p) => p.id === id); tab('documentos'); return loadDocuments(1); }
        if (a === 'supplier-history') return history('proveedor', id);
        if (a === 'detail') return detail(id);
        if (a === 'account') { state.account = state.accounts.find((c) => c.codigo === id); const f = $('cpAccountForm'); fill(f, state.account); f.elements.codigo.readOnly = true; f.elements.tipo.disabled = true; open('cpAccountDialog'); return; }
        const doc = state.documents.find((d) => d.id === id);
        if (a === 'correct-document') return documentForm({ ...doc, correction: true });
        if (a === 'edit-document') return documentForm(doc);
        if (['post', 'reverse'].includes(a)) { state.action = { action: a, doc }; const f = $('cpActionForm'); f.reset(); f.elements.fecha_contable.value = today(); f.elements.motivo.required = a === 'reverse'; $('cpActionReason').hidden = a !== 'reverse'; $('cpActionTitle').textContent = a === 'post' ? (doc.tipo === '52' ? 'Registrar guía sin deuda' : 'Contabilizar documento') : 'Anular registro internamente'; $('cpActionDescription').textContent = `${state.types[doc.tipo]} ${doc.folio} · ${money(doc.total)}`; open('cpActionDialog'); }
    });
});
$('cpJournalFilters').elements.desde.value = `${today().slice(0, 7)}-01`; $('cpJournalFilters').elements.hasta.value = today();
if (token) safely(async () => { const d = await api('/api/acceso-oficina'); identity = d.usuario || d; localStorage.setItem(identityKey, JSON.stringify(identity)); show(); await loadSuppliers(); });
