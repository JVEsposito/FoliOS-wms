import '../css/office-communications.css';
import { communicationsSession, escapeCommunications as esc, communicationsDate as date } from './shared/communications-client.js';
import { createOperationalPoller } from './shared/operational-poller.js';
import { createUuid } from './shared/uuid.js';

const base = '/api/comunicaciones';

export function createCommunicationsApp(dialog, api, onChanged) {
    const $ = selector => dialog.querySelector(selector);
    const $$ = selector => [...dialog.querySelectorAll(selector)];
    const status = $('[data-comms-status]');
    const log = $('[data-chat-messages]');
    const chatForm = $('[data-chat-form]');
    const mailForm = $('[data-mail-compose]');
    const uploadForm = $('[data-sticker-upload]');
    let opened = false, generation = 0, selection = 0, tab = 'chat', user = null;
    let conversationRequest = 0, mailRequest = 0, stickerRequest = 0;
    let conversation = null, conversations = [], conversationPage = 1, conversationsMore = false;
    let messages = [], older = false, chatReady = false, receivedCursor = 0, readState = { last: 0, busy: false };
    const renderedMessages = new Map();
    let busyChat = false, busyMail = false, busySticker = false, busyPrivate = false, ticks = 0;
    const drafts = new Map();
    let folder = 'recibidos', mailPage = 1, mailPages = 1, selectedMail = null, composing = false, replyTo = null;
    let mailIntent = null, stickerIntent = null, stickerPage = 1, stickerMore = false, stickerRows = [];
    const recipients = new Map();
    const directory = { private: { page: 1, rows: [], more: false, version: 0 }, mail: { page: 1, rows: [], more: false, version: 0 } };
    const images = new Map(), queued = new Set(), visibleImages = new Set();
    let imageQueue = [], imageGeneration = 0, imageActive = 0, recipientTimer = null;
    const alive = version => opened && generation === version && communicationsSession()?.token === user?.token && communicationsSession()?.id === user?.id;
    function notice(text = '', error = false) { if (opened) { status.textContent = text; status.dataset.error = String(error); } }
    function failed(error) { if (opened && error.name !== 'AbortError') notice(error.message || 'Sin conexión. Vuelve a intentarlo.', true); }
    function draft() {
        if (!conversation) return null;
        if (!drafts.has(conversation.id)) drafts.set(conversation.id, { text: '', sticker: null, intent: null });
        return drafts.get(conversation.id);
    }
    function intent(previous, payload) {
        const signature = JSON.stringify(payload);
        return previous?.signature === signature ? previous : { signature, id: createUuid() };
    }
    function controls() {
        const writing = busyChat || busyMail || busySticker;
        $('[data-comms-close]').disabled = writing;
        $('[data-chat-send]').disabled = busyChat || !chatReady || !conversation?.puede_enviar;
        chatForm.elements.texto.disabled = busyChat || !chatReady || !conversation?.puede_enviar;
        $('[data-stickers-open]').disabled = busyChat || !chatReady || !conversation?.puede_enviar;
        $$('[data-select-conversation]').forEach(b => { b.disabled = busyChat; });
        [...mailForm.elements].forEach(e => { e.disabled = busyMail; });
        [...uploadForm.elements].forEach(e => { e.disabled = busySticker; });
        $('[data-chat-send]').textContent = busyChat ? 'Enviando…' : 'Enviar mensaje';
        $('[data-mail-send]').textContent = busyMail ? 'Enviando…' : 'Enviar correo';
    }
    function clearImages() {
        imageGeneration += 1; imageQueue = []; queued.clear(); observer.disconnect(); visibleImages.clear();
        images.forEach(url => URL.revokeObjectURL(url)); images.clear();
        $$('img[data-comms-sticker-id]').forEach(n => n.removeAttribute('src'));
    }
    function pruneImages() {
        for (const [id, url] of images) {
            if (images.size <= 60) break;
            if ([...visibleImages].some(n => n.isConnected && n.dataset.commsStickerId === id)) continue;
            images.delete(id); URL.revokeObjectURL(url);
            $$(`img[data-comms-sticker-id="${id}"]`).forEach(n => n.removeAttribute('src'));
        }
    }
    function pumpImages() {
        while (imageActive < 4 && imageQueue.length) {
            const item = imageQueue.shift(); imageActive += 1;
            void api.request(item.path, { image: true }).then(blob => {
                if (!opened || item.version !== imageGeneration) return;
                const url = URL.createObjectURL(blob); images.set(item.id, url);
                $$(`img[data-comms-sticker-id="${item.id}"]`).forEach(n => { n.src = url; });
                pruneImages();
            }).catch(() => {}).finally(() => { imageActive -= 1; queued.delete(item.key); pumpImages(); });
        }
    }
    const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
            const node = entry.target;
            if (!entry.isIntersecting) { visibleImages.delete(node); continue; }
            visibleImages.add(node);
            const id = node.dataset.commsStickerId;
            if (images.has(id)) { if (node.getAttribute('src') !== images.get(id)) node.src = images.get(id); continue; }
            const path = node.dataset.stickerUrl;
            if (!/^\/api\/comunicaciones\/stickers\/\d+\/archivo$/.test(path || '')) continue;
            const key = `${imageGeneration}:${id}`;
            if (queued.has(key)) continue;
            queued.add(key); imageQueue.push({ id, path, key, version: imageGeneration });
        }
        pumpImages();
    }, { root: dialog, rootMargin: '100px' });
    function observeImages() {
        observer.disconnect(); visibleImages.clear();
        $$('img[data-comms-sticker-id]').forEach(node => {
            if (images.has(node.dataset.commsStickerId) && node.getAttribute('src') !== images.get(node.dataset.commsStickerId)) node.src = images.get(node.dataset.commsStickerId);
            observer.observe(node);
        });
    }
    const stickerImage = sticker => `<img loading="lazy" alt="${esc(sticker.nombre)}" data-comms-sticker-id="${Number(sticker.id)}" data-sticker-url="${esc(sticker.archivo_url)}">`;
    function nearBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 60; }
    async function markRead() {
        if (!opened || tab !== 'chat' || !conversation || document.hidden || !nearBottom() || !messages.length) return;
        // Una confirmación propia puede adelantarse a mensajes ajenos aún no descargados.
        const last = receivedCursor;
        const state = readState;
        if (state.busy || last <= state.last) return;
        state.busy = true;
        const version = selection, app = generation, id = conversation.id;
        try {
            await api.request(`${base}/chat/conversaciones/${id}/leer`, { method: 'POST', body: { hasta_id: last } });
            if (alive(app) && selection === version) { state.last = Math.max(state.last, last); onChanged(); }
        } catch (error) { failed(error); }
        finally { state.busy = false; }
    }
    function renderMessages({ bottom = false, prepend = false } = {}) {
        const height = log.scrollHeight, top = log.scrollTop;
        if (!renderedMessages.size) log.replaceChildren();
        for (const m of messages) {
            if (renderedMessages.has(m.id)) continue;
            const template = document.createElement('template');
            template.innerHTML = `<article class="comms-message ${m.remitente.id === user.id ? 'is-mine' : ''}" data-chat-message="${Number(m.id)}"><header>${esc(m.remitente.nombre)}</header>${m.texto !== null ? `<p>${esc(m.texto)}</p>` : ''}${m.sticker ? stickerImage(m.sticker) : ''}<time>${esc(date(m.creado_at))}</time></article>`;
            const node = template.content.firstElementChild;
            const next = messages.find(other => other.id > m.id && renderedMessages.has(other.id));
            log.insertBefore(node, next ? renderedMessages.get(next.id) : null); renderedMessages.set(m.id, node);
        }
        if (!messages.length) { const empty = document.createElement('p'); empty.textContent = 'Aún no hay mensajes. Puedes escribir el primero.'; log.append(empty); }
        $('[data-chat-older]').hidden = !older;
        observeImages();
        if (bottom) { log.scrollTop = log.scrollHeight; $('[data-chat-bottom]').hidden = true; void markRead(); }
        else if (prepend) log.scrollTop = log.scrollHeight - height + top;
        else { log.scrollTop = top; $('[data-chat-bottom]').hidden = nearBottom(); }
    }
    function renderDraft() {
        const value = draft();
        chatForm.elements.texto.value = value?.text || '';
        const region = $('[data-chat-sticker]');
        region.hidden = !value?.sticker;
        region.innerHTML = value?.sticker ? `${stickerImage(value.sticker)}<span>${esc(value.sticker.nombre)}</span><button type="button" data-chat-sticker-remove>Quitar sticker</button>` : '';
        observeImages(); controls();
    }
    function renderConversations() {
        $('[data-conversation-list]').innerHTML = conversations.map(c => `<button type="button" class="comms-row ${c.tiene_no_leidos ? 'is-unread' : ''}" data-select-conversation="${c.id}" aria-current="${conversation?.id === c.id}"><strong>${esc(c.nombre)}</strong><small>${c.tipo === 'global' ? 'Todos los usuarios internos' : 'Conversación privada'}</small>${c.extracto ? `<small>${esc(c.extracto)}</small>` : ''}</button>`).join('');
        $('[data-conversations-more]').hidden = !conversationsMore; controls();
    }
    async function loadConversations({ more = false } = {}) {
        const version = generation, page = more ? conversationPage + 1 : 1;
        const request = ++conversationRequest;
        const result = await api.request(`${base}/chat/conversaciones?pagina=${page}`);
        if (!alive(version) || request !== conversationRequest) return;
        conversations = more ? [...conversations, ...result.data.filter(c => !conversations.some(a => a.id === c.id))] : result.data;
        conversationPage = page; conversationsMore = page < result.ultima_pagina; renderConversations();
    }
    async function loadMessages(mode = 'new') {
        if (!conversation) return;
        const version = selection, app = generation, id = conversation.id;
        const params = new URLSearchParams();
        if (mode === 'new' && chatReady) params.set('despues_id', receivedCursor);
        if (mode === 'old' && messages.length) params.set('antes_id', messages[0].id);
        const bottom = mode === 'initial' || nearBottom();
        const result = await api.request(`${base}/chat/conversaciones/${id}/mensajes?${params}`);
        if (!alive(app) || selection !== version || tab !== 'chat') return;
        conversation = result.conversacion;
        $('[data-conversation-title]').textContent = conversation.nombre;
        if (mode !== 'new' || !chatReady) older = result.hay_mas_anteriores;
        const map = new Map(messages.map(m => [m.id, m]));
        const received = result.data.filter(m => m.conversacion_id === id);
        received.forEach(m => map.set(m.id, m));
        if (mode !== 'old' && received.length) receivedCursor = Math.max(receivedCursor, ...received.map(m => m.id));
        messages = [...map.values()].sort((a, b) => a.id - b.id); chatReady = true;
        const current = draft();
        if (current?.intent && result.data.some(m => m.remitente.id === user.id && m.operacion_id === current.intent.id)) {
            current.text = ''; current.sticker = null; current.intent = null; renderDraft();
        }
        if (result.data.length || mode === 'initial') renderMessages({ bottom, prepend: mode === 'old' });
        if (mode === 'new' && nearBottom()) void markRead();
        controls();
    }
    async function selectConversation(id) {
        if (busyChat) return;
        selection += 1; conversation = conversations.find(c => c.id === id) || { id, nombre: 'Conversación', puede_enviar: false };
        messages = []; older = false; chatReady = false; receivedCursor = 0; renderedMessages.clear(); readState = { last: 0, busy: false }; clearImages();
        $('[data-sticker-panel]').hidden = true; $('[data-chat-bottom]').hidden = true;
        $('[data-conversation-title]').textContent = conversation.nombre;
        log.innerHTML = '<p>Cargando mensajes…</p>'; renderDraft(); renderConversations();
        await loadMessages('initial');
    }
    async function sendChat(event) {
        event.preventDefault();
        if (busyChat || !chatReady || !conversation?.puede_enviar) return;
        const value = draft(), id = conversation.id, app = generation, selected = selection;
        value.text = chatForm.elements.texto.value;
        const payload = { texto: value.text.trim() || null, sticker_id: value.sticker?.id || null };
        if (!payload.texto && !payload.sticker_id) { notice('Escribe un mensaje o selecciona un sticker.', true); return; }
        value.intent = intent(value.intent, { conversacion_id: id, ...payload });
        busyChat = true; controls(); notice('Enviando mensaje…');
        try {
            const result = await api.request(`${base}/chat/conversaciones/${id}/mensajes`, { method: 'POST', body: { ...payload, operacion_id: value.intent.id } });
            if (!alive(app) || selected !== selection) return;
            if (!messages.some(m => m.id === result.data.id)) messages.push(result.data);
            messages.sort((a, b) => a.id - b.id); value.text = ''; value.sticker = null; value.intent = null;
            renderDraft(); renderMessages({ bottom: true }); notice('Mensaje enviado.'); onChanged();
            void loadConversations().catch(() => {});
        } catch (error) {
            if (alive(app) && error.name !== 'AbortError') notice(`${error.message} Si no se confirmó, reintenta el mismo mensaje sin cambiarlo.`, true);
            else if (alive(app)) notice('Envío sin confirmar. Reintenta el mismo mensaje para evitar duplicados.', true);
        } finally { if (alive(app)) { busyChat = false; controls(); } }
    }
    async function loadDirectory(mode, more = false) {
        const state = directory[mode], requestVersion = ++state.version, app = generation;
        if (!more) {
            state.rows = [];
            const region = mode === 'private' ? $('[data-private-users]') : $('[data-recipient-options]');
            region.textContent = 'Buscando personas…';
        }
        const search = mode === 'private' ? $('[data-private-search]').elements.buscar.value : $('[data-recipient-search]').value;
        const page = more ? state.page + 1 : 1;
        const result = await api.request(`${base}/usuarios?${new URLSearchParams({ buscar: search, pagina: page })}`);
        if (!alive(app) || requestVersion !== state.version) return;
        state.rows = more ? [...state.rows, ...result.data.filter(u => !state.rows.some(a => a.id === u.id))] : result.data;
        state.page = page; state.more = page < result.ultima_pagina;
        if (mode === 'private') {
            $('[data-private-users]').innerHTML = state.rows.map(u => `<button class="comms-row" type="button" data-open-user="${u.id}">${esc(u.nombre)}</button>`).join('') || '<p>No hay personas para esta búsqueda.</p>';
            $('[data-private-more]').hidden = !state.more;
        } else {
            $('[data-recipient-options]').innerHTML = state.rows.map(u => `<label><input type="checkbox" data-recipient="${u.id}" ${recipients.has(u.id) ? 'checked' : ''}> ${esc(u.nombre)}</label>`).join('') || '<p>No hay destinatarios para esta búsqueda.</p>';
            $('[data-recipients-more]').hidden = !state.more; controls();
        }
    }
    async function openPrivate(id) {
        if (busyPrivate) return;
        busyPrivate = true; const app = generation;
        try {
            const { data } = await api.request(`${base}/chat/privados`, { method: 'POST', body: { usuario_id: id } });
            if (!alive(app) || tab !== 'chat') return;
            conversationRequest += 1;
            if (!conversations.some(c => c.id === data.id)) conversations.push(data);
            $('[data-private-picker]').hidden = true; $('[data-conversation-list]').hidden = false;
            await selectConversation(data.id);
        } catch (error) { failed(error); }
        finally { if (alive(app)) busyPrivate = false; }
    }
    async function loadStickers(more = false) {
        const app = generation, page = more ? stickerPage + 1 : 1;
        const request = ++stickerRequest;
        const result = await api.request(`${base}/stickers?${new URLSearchParams({ buscar: $('[data-sticker-search]').elements.buscar.value, pagina: page })}`);
        if (!alive(app) || request !== stickerRequest || $('[data-sticker-panel]').hidden) return;
        stickerRows = more ? [...stickerRows, ...result.data.filter(s => !stickerRows.some(a => a.id === s.id))] : result.data;
        stickerPage = page; stickerMore = page < result.ultima_pagina;
        $('[data-sticker-grid]').innerHTML = stickerRows.map(s => `<article><button type="button" data-pick-sticker="${s.id}" aria-label="Usar sticker ${esc(s.nombre)}">${stickerImage(s)}<small>${esc(s.nombre)}</small></button>${s.puede_ocultar ? `<button type="button" data-sticker-hide="${s.id}">Quitar de galería</button>` : ''}</article>`).join('') || '<p>Aún no hay stickers. Puedes cargar el primero.</p>';
        $('[data-stickers-more]').hidden = !stickerMore; observeImages();
    }
    async function uploadSticker(event) {
        event.preventDefault(); if (busySticker) return;
        const file = uploadForm.elements.archivo.files[0], name = uploadForm.elements.nombre.value.trim();
        if (!file || !name) return;
        if (!['image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 2 * 1024 * 1024) { notice('Elige un PNG, WebP o GIF de hasta 2 MB.', true); return; }
        if (!stickerIntent || stickerIntent.file !== file || stickerIntent.name !== name) stickerIntent = { id: createUuid(), file, name };
        const body = new FormData(); body.append('archivo', file); body.append('nombre', name); body.append('operacion_id', stickerIntent.id);
        busySticker = true; controls(); const app = generation;
        try {
            await api.request(`${base}/stickers`, { method: 'POST', body });
            if (!alive(app)) return;
            stickerIntent = null; uploadForm.reset(); notice('Sticker cargado y disponible para el equipo.');
            void loadStickers().catch(() => notice('Sticker cargado. Actualiza la galería para verlo.'));
        } catch (error) { failed(error); }
        finally { if (alive(app)) { busySticker = false; controls(); } }
    }
    function showRecipients() {
        $('[data-mail-recipients]').innerHTML = [...recipients.values()].map(u => `<button type="button" data-remove-recipient="${u.id}" aria-label="Quitar a ${esc(u.nombre)}">${esc(u.nombre)} ×</button>`).join('');
    }
    async function loadMails() {
        const app = generation, requestedFolder = folder, requestedPage = mailPage;
        const request = ++mailRequest;
        const result = await api.request(`${base}/correos?${new URLSearchParams({ bandeja: folder, pagina: mailPage, buscar: $('[data-mail-search]').elements.buscar.value })}`);
        if (!alive(app) || request !== mailRequest || folder !== requestedFolder || mailPage !== requestedPage) return;
        mailPages = result.ultima_pagina;
        $('[data-mail-list]').innerHTML = result.data.map(m => `<button type="button" class="comms-row ${!m.leido ? 'is-unread' : ''}" data-open-mail="${m.id}" aria-current="${selectedMail?.id === m.id}"><strong>${esc(m.asunto)}</strong><small>${esc(folder === 'enviados' ? m.destinatarios.map(u => u.nombre).join(', ') : m.remitente.nombre)}</small><small>${esc(date(m.creado_at))}</small></button>`).join('') || '<p>No hay correos en esta bandeja.</p>';
        $('[data-mail-page]').textContent = `${result.pagina} / ${result.ultima_pagina}`;
        $('[data-mail-prev]').disabled = mailPage <= 1; $('[data-mail-next]').disabled = mailPage >= mailPages;
    }
    function renderMail() {
        if (!selectedMail) { $('[data-mail-detail]').innerHTML = '<p>Selecciona un correo para leerlo.</p>'; return; }
        const m = selectedMail;
        $('[data-mail-detail]').innerHTML = `<h3>${esc(m.asunto)}</h3><small>De: ${esc(m.remitente.nombre)}</small><small>Para: ${esc(m.destinatarios.map(u => u.nombre).join(', '))}</small><small>${esc(date(m.creado_at))}</small><div class="comms-mail-text">${esc(m.texto)}</div><div class="comms-compose-actions"><button type="button" data-mail-reply>Responder</button>${m.es_destinatario ? `<button type="button" data-mail-read-toggle>${m.leido ? 'Marcar no leído' : 'Marcar leído'}</button><button type="button" data-mail-archive>${m.archivado ? 'Restaurar a recibidos' : 'Archivar'}</button>` : ''}</div>`;
    }
    async function openMail(id) {
        if (composing) { notice('Envía o cancela el borrador antes de abrir otro correo.', true); return; }
        const app = generation, view = ++selection;
        selectedMail = null; $('[data-mail-detail]').innerHTML = '<p>Cargando correo…</p>';
        const { data } = await api.request(`${base}/correos/${id}`);
        if (!alive(app) || view !== selection || tab !== 'mail') return;
        selectedMail = data; renderMail();
        if (data.es_destinatario && !data.leido && !document.hidden) {
            await api.request(`${base}/correos/${id}/leer`, { method: 'POST', body: { leido: true } });
            if (!alive(app) || view !== selection) return;
            selectedMail.leido = true; renderMail(); onChanged(); await loadMails();
        }
    }
    async function compose(reply = false) {
        if (composing) return;
        composing = true; replyTo = reply ? selectedMail?.id : null; mailIntent = null; recipients.clear(); mailForm.reset();
        if (reply && selectedMail) {
            const people = selectedMail.remitente.id === user.id ? selectedMail.destinatarios : [selectedMail.remitente];
            people.filter(p => p.id !== user.id).forEach(p => recipients.set(p.id, p));
            mailForm.elements.asunto.value = /^Re:/i.test(selectedMail.asunto) ? selectedMail.asunto : `Re: ${Array.from(selectedMail.asunto).slice(0, 156).join('')}`;
        }
        $('[data-mail-compose-title]').textContent = reply ? 'Responder correo' : 'Nuevo correo';
        $('[data-mail-detail]').hidden = true; mailForm.hidden = false; showRecipients();
        await loadDirectory('mail');
    }
    async function sendMail(event) {
        event.preventDefault(); if (busyMail) return;
        if (!recipients.size) { notice('Selecciona al menos un destinatario.', true); return; }
        const payload = { destinatarios: [...recipients.keys()].sort((a, b) => a - b), asunto: mailForm.elements.asunto.value.trim(), texto: mailForm.elements.texto.value.trim(), respuesta_a_id: replyTo };
        mailIntent = intent(mailIntent, payload); const app = generation;
        busyMail = true; controls(); notice('Enviando correo…');
        try {
            const result = await api.request(`${base}/correos`, { method: 'POST', body: { ...payload, operacion_id: mailIntent.id } });
            if (!alive(app)) return;
            composing = false; mailIntent = null; replyTo = null; recipients.clear(); mailForm.reset(); mailForm.hidden = true;
            $('[data-mail-detail]').hidden = false; selectedMail = result.data; folder = 'enviados'; mailPage = 1;
            renderMail(); showFolders(); notice('Correo enviado.'); onChanged(); void loadMails().catch(() => {});
        } catch (error) { if (alive(app)) notice(`${error.message || 'Envío sin confirmar.'} Reintenta sin cambiar el borrador para evitar duplicados.`, true); }
        finally { if (alive(app)) { busyMail = false; controls(); } }
    }
    function showFolders() { $$('[data-mail-folder]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.mailFolder === folder))); }
    async function chooseFolder(value) {
        if (composing) { notice('Envía o cancela el borrador antes de cambiar de bandeja.', true); return; }
        selection += 1; folder = value; mailPage = 1; selectedMail = null; renderMail(); showFolders(); await loadMails();
    }
    async function switchTab(value) {
        if (busyChat || busyMail || busySticker) return;
        selection += 1; tab = value; clearImages();
        $$('[data-comms-tab]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.commsTab === value)));
        $('[data-comms-chat]').hidden = value !== 'chat'; $('[data-comms-mail]').hidden = value !== 'mail'; notice();
        if (value === 'chat') {
            if (conversation) await selectConversation(conversation.id);
            else { await loadConversations(); if (conversations.length) await selectConversation(conversations[0].id); }
        } else {
            showFolders();
            if (!composing && !selectedMail) renderMail();
            await loadMails();
        }
    }
    const poller = createOperationalPoller(async () => {
        if (!opened) return;
        ticks += 1;
        if (tab === 'chat' && !busyChat) {
            if (conversation) await loadMessages(chatReady ? 'new' : 'initial');
            else { await loadConversations(); if (conversations.length) await selectConversation(conversations[0].id); }
            if (ticks % 3 === 0 && conversationPage === 1) await loadConversations();
        } else if (tab === 'mail' && !composing && ticks % 3 === 0) await loadMails();
    }, { intervalMs: 5000, canRun: () => opened && Boolean(communicationsSession()), onError: error => { if (error.name !== 'AbortError') notice('No se pudo actualizar. Se conserva lo recibido; puedes reintentar.', true); } });

    dialog.addEventListener('click', event => {
        const button = event.target.closest('button'); if (!button) return;
        const run = async () => {
            if (button.hasAttribute('data-comms-close')) { dialog.close(); return; }
            if (button.dataset.commsTab) return switchTab(button.dataset.commsTab);
            if (button.hasAttribute('data-comms-refresh')) {
                if (tab === 'chat') { if (conversation) return chatReady ? loadMessages() : selectConversation(conversation.id); await loadConversations(); if (conversations.length) return selectConversation(conversations[0].id); }
                return loadMails();
            }
            if (button.dataset.selectConversation) return selectConversation(Number(button.dataset.selectConversation));
            if (button.hasAttribute('data-conversations-more')) return loadConversations({ more: true });
            if (button.hasAttribute('data-private-new')) { $('[data-private-picker]').hidden = false; $('[data-conversation-list]').hidden = true; return loadDirectory('private'); }
            if (button.hasAttribute('data-private-cancel')) { $('[data-private-picker]').hidden = true; $('[data-conversation-list]').hidden = false; return; }
            if (button.hasAttribute('data-private-more')) return loadDirectory('private', true);
            if (button.dataset.openUser) return openPrivate(Number(button.dataset.openUser));
            if (button.hasAttribute('data-chat-older')) return loadMessages('old');
            if (button.hasAttribute('data-chat-bottom')) { log.scrollTop = log.scrollHeight; button.hidden = true; return markRead(); }
            if (button.hasAttribute('data-stickers-open')) { $('[data-sticker-panel]').hidden = false; return loadStickers(); }
            if (button.hasAttribute('data-stickers-close')) { $('[data-sticker-panel]').hidden = true; return; }
            if (button.hasAttribute('data-stickers-more')) return loadStickers(true);
            if (button.dataset.pickSticker) { if (!draft() || busyChat) return; draft().sticker = stickerRows.find(s => s.id === Number(button.dataset.pickSticker)); $('[data-sticker-panel]').hidden = true; renderDraft(); return; }
            if (button.hasAttribute('data-chat-sticker-remove')) { if (draft()) draft().sticker = null; renderDraft(); return; }
            if (button.dataset.stickerHide) { await api.request(`${base}/stickers/${Number(button.dataset.stickerHide)}`, { method: 'DELETE' }); notice('Sticker quitado de la galería. Los mensajes anteriores se conservan.'); return loadStickers(); }
            if (button.dataset.mailFolder) return chooseFolder(button.dataset.mailFolder);
            if (button.hasAttribute('data-mail-new')) return compose();
            if (button.dataset.openMail) return openMail(Number(button.dataset.openMail));
            if (button.hasAttribute('data-mail-prev') || button.hasAttribute('data-mail-next')) { mailPage += button.hasAttribute('data-mail-prev') ? -1 : 1; return loadMails(); }
            if (button.hasAttribute('data-mail-reply')) return compose(true);
            if (button.hasAttribute('data-mail-cancel')) { composing = false; mailIntent = null; recipients.clear(); replyTo = null; mailForm.reset(); mailForm.hidden = true; $('[data-mail-detail]').hidden = false; notice('Borrador cancelado.'); return; }
            if (button.hasAttribute('data-recipients-more')) return loadDirectory('mail', true);
            if (button.dataset.removeRecipient) { recipients.delete(Number(button.dataset.removeRecipient)); showRecipients(); $$('[data-recipient]').forEach(n => { n.checked = recipients.has(Number(n.dataset.recipient)); }); return; }
            if (button.hasAttribute('data-mail-read-toggle') && selectedMail) {
                const m = selectedMail, app = generation, view = selection, leido = !m.leido;
                await api.request(`${base}/correos/${m.id}/leer`, { method: 'POST', body: { leido } });
                if (!alive(app) || view !== selection || selectedMail?.id !== m.id) return;
                selectedMail.leido = leido; renderMail(); onChanged(); return loadMails();
            }
            if (button.hasAttribute('data-mail-archive') && selectedMail) {
                const m = selectedMail, app = generation, view = selection;
                await api.request(`${base}/correos/${m.id}/archivar`, { method: 'POST', body: { archivado: !m.archivado } });
                if (!alive(app) || view !== selection || selectedMail?.id !== m.id) return;
                selectedMail = null; renderMail(); onChanged(); return loadMails();
            }
        };
        void run().catch(failed);
    });
    chatForm.addEventListener('submit', event => void sendChat(event).catch(failed));
    chatForm.elements.texto.addEventListener('input', () => { if (draft()) draft().text = chatForm.elements.texto.value; });
    mailForm.addEventListener('submit', event => void sendMail(event).catch(failed));
    uploadForm.addEventListener('submit', event => void uploadSticker(event).catch(failed));
    $('[data-private-search]').addEventListener('submit', event => { event.preventDefault(); void loadDirectory('private').catch(failed); });
    $('[data-sticker-search]').addEventListener('submit', event => { event.preventDefault(); void loadStickers().catch(failed); });
    $('[data-mail-search]').addEventListener('submit', event => { event.preventDefault(); mailPage = 1; void loadMails().catch(failed); });
    $('[data-recipient-search]').addEventListener('input', () => { clearTimeout(recipientTimer); recipientTimer = setTimeout(() => void loadDirectory('mail').catch(failed), 300); });
    $('[data-recipient-options]').addEventListener('change', event => {
        const id = Number(event.target.dataset.recipient), person = directory.mail.rows.find(p => p.id === id);
        if (!person) return; if (event.target.checked) recipients.set(id, person); else recipients.delete(id); showRecipients();
    });
    log.addEventListener('scroll', () => { if (nearBottom()) { $('[data-chat-bottom]').hidden = true; void markRead(); } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void markRead(); });
    dialog.addEventListener('cancel', event => { if (busyChat || busyMail || busySticker) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (dialog.open) return;
        opened = false; generation += 1; selection += 1; poller.stop(); api.reset(); clearImages(); clearTimeout(recipientTimer);
        messages = []; conversation = null; selectedMail = null; chatReady = false; receivedCursor = 0; renderedMessages.clear();
        log.replaceChildren(); $('[data-conversation-list]').replaceChildren(); $('[data-mail-list]').replaceChildren(); $('[data-mail-detail]').replaceChildren();
        busyChat = false; busyMail = false; busySticker = false; busyPrivate = false;
        document.querySelector('[data-communications-open]')?.focus();
    });
    return {
        async open() {
            user = communicationsSession(); if (!user) return;
            if (dialog.open) return;
            generation += 1; opened = true; tab = 'chat'; dialog.showModal(); controls(); notice('Actualizando conversaciones…');
            $('[data-private-picker]').hidden = true; $('[data-conversation-list]').hidden = false; $('[data-sticker-panel]').hidden = true;
            try { await switchTab('chat'); notice('Actualización automática mientras el panel está abierto.'); }
            catch (error) { failed(error); }
            poller.start();
        },
        reset() {
            opened = false; generation += 1; selection += 1; poller.stop(); clearImages(); clearTimeout(recipientTimer);
            if (dialog.open) dialog.close();
            drafts.clear(); recipients.clear(); conversations = []; messages = []; renderedMessages.clear(); receivedCursor = 0; stickerRows = []; conversation = null; selectedMail = null;
            composing = false; replyTo = null; mailIntent = null; stickerIntent = null; user = null;
            folder = 'recibidos'; mailPage = 1; conversationPage = 1; older = false; chatReady = false;
            busyChat = false; busyMail = false; busySticker = false; busyPrivate = false;
            chatForm.reset(); mailForm.reset(); uploadForm.reset(); mailForm.hidden = true; $('[data-mail-detail]').hidden = false;
            $('[data-private-search]').reset(); $('[data-mail-search]').reset(); $('[data-sticker-search]').reset();
            $('[data-private-picker]').hidden = true; $('[data-conversation-list]').hidden = false; $('[data-sticker-panel]').hidden = true; $('[data-chat-bottom]').hidden = true;
            $$('[data-private-users], [data-recipient-options], [data-mail-recipients], [data-chat-sticker], [data-sticker-grid], [data-mail-detail], [data-mail-list], [data-chat-messages], [data-conversation-list]').forEach(n => n.replaceChildren());
            Object.values(directory).forEach(d => { d.rows = []; d.page = 1; d.version += 1; });
            status.textContent = '';
        },
    };
}
