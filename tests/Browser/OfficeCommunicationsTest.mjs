import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const user = { id: 17, nombre: 'Ivan Sanchez', rol: 'guardia_porteria', puede_consultar_porteria: true, modulos_acceso: ['materia-prima.porteria'] };
const people = [{ id: 18, nombre: 'Sharon B' }, { id: 19, nombre: 'Javier E' }];
const date = '2026-11-13T01:00:00Z';
const png = new PNG({ width: 32, height: 32 }); png.data.fill(200);
const picture = PNG.sync.write(png);
const launch = () => chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });

async function setup(browser, { moderation = false } = {}) {
    const directory = people.map(person => moderation && person.id === 18
        ? { ...person, foto_version: '7f686a67-2914-4bd0-8df3-d0bdda65fc29' } : person);
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.addInitScript(identity => {
        localStorage.setItem('estiba_wms_office_token', 'comms-fixture');
        localStorage.setItem('estiba_wms_office_identity', JSON.stringify(identity));
    }, user);
    const state = { user, messageId: 300, mailId: 400, failChat: false, failMail: false, injectBeforeSend: false, readCalls: [], chatCalls: [], mailCalls: [], stickers: [], imageCalls: [], holdPrivate: null, groups: new Map(), moderationCalls: [], deletions: [],
        conversations: [{ id: 1, tipo: 'global', nombre: 'Chat global', puede_enviar: true, puede_moderar: moderation, tiene_no_leidos: true, ultimo_mensaje_id: 101 },
            { id: 2, tipo: 'privado', nombre: 'Sharon B', puede_enviar: true, tiene_no_leidos: true, ultimo_mensaje_id: 201 }],
        messages: new Map([[1, [{ id: 101, conversacion_id: 1, remitente: directory[0], texto: 'Aviso <img src=x onerror="window.__chatXss=1">', sticker: null, creado_at: date, operacion_id: null }]],
            [2, [{ id: 201, conversacion_id: 2, remitente: people[0], texto: 'Privado para Ivan', sticker: null, creado_at: date, operacion_id: null }]]]),
        mails: [{ id: 201, asunto: 'Cambio de turno', texto: '<script>window.__mailXss=1</script>\nMensaje del equipo.', remitente: people[0], destinatarios: [user], creado_at: date, es_destinatario: true, leido: false, archivado: false }],
    };
    await context.route('http://localhost/**', async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname, method = req.method();
        if (path === '/oficina/porteria') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/porteria.html'), 'utf8') });
        if (path === '/FoliOS') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>FoliOS</title><p>Ingresar</p>' });
        if (path.startsWith('/build/') || path.startsWith('/brand/')) {
            try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
            catch { return route.fulfill({ status: 404 }); }
        }
        if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: state.user } });
        if (path === '/api/usuario/perfil') return route.fulfill({ json: { data: { id: state.user.id, foto_perfil_version: null } } });
        if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: null, planta: 'Pruebas', avisos_cierre: [], avisos_materiales: [] } } });
        if (path === '/api/porteria/catalogos') return route.fulfill({ json: { clientes: [], ahora: date } });
        if (path === '/api/porteria/ingresos') return route.fulfill({ json: { data: [], pagina: 1, ultima_pagina: 1, total: 0, puede_registrar: true } });
        if (!path.startsWith('/api/comunicaciones/')) return route.fulfill({ status: 404, json: {} });
        assert.match(req.headers().authorization, /^Bearer /);
        assert.equal(url.search.includes('comms-fixture'), false);
        if (path.endsWith('/resumen')) return route.fulfill({ json: { data: { chat_no_leido: false, correos_no_leidos: state.mails.filter(m => m.destinatarios.some(p => p.id === state.user.id) && !m.leido && !m.archivado).length } } });
        if (path.endsWith('/usuarios')) return route.fulfill({ json: { data: directory.filter(p => p.id !== state.user.id && p.nombre.toLowerCase().includes((url.searchParams.get('buscar') || '').toLowerCase())), pagina: 1, ultima_pagina: 1 } });
        if (path.endsWith('/chat/conversaciones')) return route.fulfill({ json: { data: state.conversations.filter(c => c.tipo === 'global' || state.user.id === 17), pagina: 1, ultima_pagina: 1 } });
        if (path.endsWith('/chat/privados')) return route.fulfill({ json: { data: state.conversations[1] } });
        if (/\/usuarios\/18\/foto$/.test(path)) return route.fulfill({ contentType: 'image/png', body: picture });
        if (path.endsWith('/chat/grupos') && method === 'POST') {
            const payload = req.postDataJSON(), id = 3;
            const group = { id, tipo: 'grupo', nombre: payload.nombre, puede_enviar: true, puede_moderar: moderation };
            state.groups.set(id, { ...group, creador_id: user.id, puede_gestionar: true, miembros: [user, ...directory.filter(p => payload.miembros.includes(p.id))] });
            state.conversations.push(group);
            state.messages.set(id, []);
            return route.fulfill({ json: { data: group } });
        }
        if (path.endsWith('/chat/grupos/3') && method === 'GET') return route.fulfill({ json: { data: state.groups.get(3) } });
        if (path.endsWith('/chat/conversaciones/1/silencios/18')) {
            state.moderationCalls.push({ method, body: method === 'PUT' ? req.postDataJSON() : null });
            return route.fulfill({ json: { data: { confirmado: true } } });
        }
        if (path.endsWith('/chat/conversaciones/1/mensajes/101') && method === 'DELETE') {
            const message = state.messages.get(1).find(m => m.id === 101);
            Object.assign(message, { eliminado: true, texto: null, sticker: null, referencias: [] });
            state.deletions.push(101);
            return route.fulfill({ json: { data: message } });
        }
        const chat = path.match(/\/chat\/conversaciones\/(\d+)\/mensajes$/);
        if (chat) {
            const id = Number(chat[1]), rows = state.messages.get(id);
            if (method === 'GET') {
                let data = [...rows];
                if (url.searchParams.has('despues_id')) data = data.filter(m => m.id > Number(url.searchParams.get('despues_id')));
                if (url.searchParams.has('antes_id')) data = data.filter(m => m.id < Number(url.searchParams.get('antes_id')));
                const result = { conversacion: state.conversations.find(c => c.id === id), data, hay_mas_anteriores: false, hay_mas_nuevos: false,
                    revision_moderacion: state.deletions.length, mensajes_eliminados: id === 1 && url.searchParams.has('revision_moderacion')
                        ? state.deletions.slice(Number(url.searchParams.get('revision_moderacion'))) : [] };
                if (id === 2 && state.holdPrivate) { const hold = state.holdPrivate; hold.started(); await hold.promise; }
                try { return await route.fulfill({ json: result }); } catch { return; }
            }
            const payload = req.postDataJSON(); state.chatCalls.push({ conversation: id, ...payload });
            if (state.failChat) { state.failChat = false; return route.abort('failed'); }
            if (state.injectBeforeSend) {
                state.injectBeforeSend = false;
                rows.push({ id: ++state.messageId, conversacion_id: id, remitente: people[0], texto: 'Entrante durante el envío', sticker: null, creado_at: date, operacion_id: null });
            }
            let message = rows.find(m => m.operacion_id === payload.operacion_id);
            if (!message) { message = { id: ++state.messageId, conversacion_id: id, remitente: state.user, texto: payload.texto, sticker: state.stickers.find(s => s.id === payload.sticker_id) || null, creado_at: date, operacion_id: payload.operacion_id }; rows.push(message); }
            state.conversations.find(c => c.id === id).ultimo_mensaje_id = message.id;
            return route.fulfill({ json: { data: message } });
        }
        if (/\/chat\/conversaciones\/\d+\/leer$/.test(path)) { state.readCalls.push(req.postDataJSON()); return route.fulfill({ json: { data: { confirmado: true } } }); }
        if (path.endsWith('/stickers')) {
            if (method === 'POST') {
                assert.match(req.headers()['content-type'], /^multipart\/form-data/);
                assert.ok(req.postDataBuffer().includes(Buffer.from('name="operacion_id"')));
                state.stickers.push({ id: 1, nombre: 'Listo', activo: true, puede_ocultar: true, archivo_url: '/api/comunicaciones/stickers/1/archivo' });
                return route.fulfill({ json: { data: state.stickers[0] } });
            }
            return route.fulfill({ json: { data: state.stickers.filter(s => s.activo), pagina: 1, ultima_pagina: 1 } });
        }
        if (/\/stickers\/\d+\/archivo$/.test(path)) { state.imageCalls.push(req.headers().authorization); return route.fulfill({ contentType: 'image/png', body: picture }); }
        if (/\/stickers\/\d+$/.test(path) && method === 'DELETE') { state.stickers[0].activo = false; return route.fulfill({ json: { data: { confirmado: true } } }); }
        if (path.endsWith('/correos')) {
            if (method === 'POST') {
                const payload = req.postDataJSON(); state.mailCalls.push(payload);
                if (state.failMail) { state.failMail = false; return route.abort('failed'); }
                let mail = state.mails.find(m => m.operacion_id === payload.operacion_id);
                if (!mail) { mail = { id: ++state.mailId, ...payload, remitente: state.user, destinatarios: payload.destinatarios.map(id => people.find(p => p.id === id)), es_destinatario: false, leido: true, archivado: false, creado_at: date }; state.mails.push(mail); }
                return route.fulfill({ json: { data: mail } });
            }
            const box = url.searchParams.get('bandeja') || 'recibidos', search = url.searchParams.get('buscar') || '';
            const mails = state.mails.filter(m => (box === 'enviados' ? m.remitente.id === state.user.id : m.destinatarios.some(p => p.id === state.user.id) && m.archivado === (box === 'archivados')) && `${m.asunto} ${m.texto}`.includes(search));
            return route.fulfill({ json: { data: [...mails].reverse(), pagina: 1, ultima_pagina: 1, total: mails.length } });
        }
        const mail = path.match(/\/correos\/(\d+)(?:\/(leer|archivar))?$/);
        if (mail) {
            const current = state.mails.find(m => m.id === Number(mail[1]));
            if (mail[2] === 'leer') current.leido = req.postDataJSON().leido;
            if (mail[2] === 'archivar') current.archivado = req.postDataJSON().archivado;
            return route.fulfill({ json: { data: current } });
        }
        return route.fulfill({ status: 404, json: {} });
    });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost/oficina/porteria');
    const button = page.getByRole('button', { name: /Abrir chat y correo/ });
    await button.click();
    const dialog = page.getByRole('dialog', { name: 'Comunicaciones', exact: true });
    await dialog.locator('[data-chat-message="101"]').waitFor();
    return { context, page, dialog, state, errors };
}

test('comunicaciones: chat global, reintento, sticker cargable, privado y vista móvil', async () => {
    const browser = await launch();
    try {
        const { context, page, dialog, state, errors } = await setup(browser);
        const log = dialog.getByRole('log', { name: 'Mensajes', exact: true });
        assert.ok(await dialog.evaluate(n => {
            const r = n.getBoundingClientRect();
            return Math.abs(r.left - (innerWidth - r.width) / 2) < 2 && Math.abs(r.top - (innerHeight - r.height) / 2) < 2;
        }));
        assert.equal(await log.locator('[data-chat-message="101"] img').count(), 0);
        assert.equal(await page.evaluate(() => window.__chatXss), undefined);
        state.failChat = true;
        await dialog.locator('#commsChatText').fill('Mensaje <b>seguro</b>');
        await dialog.getByRole('button', { name: 'Enviar mensaje', exact: true }).click();
        await dialog.locator('[data-comms-status]').filter({ hasText: /reintenta el mismo mensaje/i }).waitFor();
        assert.equal(await dialog.locator('#commsChatText').inputValue(), 'Mensaje <b>seguro</b>');
        await dialog.getByRole('button', { name: 'Enviar mensaje', exact: true }).click();
        await log.getByText('Mensaje <b>seguro</b>', { exact: true }).waitFor();
        assert.equal(state.chatCalls.length, 2);
        assert.equal(state.chatCalls[0].operacion_id, state.chatCalls[1].operacion_id);
        assert.equal(state.messages.get(1).length, 2);
        await dialog.getByRole('button', { name: 'Stickers', exact: true }).click();
        await dialog.getByText('Cargar un sticker', { exact: true }).click();
        const upload = dialog.locator('[data-sticker-upload]');
        await upload.locator('[name="nombre"]').fill('Listo');
        await upload.locator('[name="archivo"]').setInputFiles({ name: 'listo.png', mimeType: 'image/png', buffer: picture });
        await upload.getByRole('button', { name: 'Subir sticker', exact: true }).click();
        await dialog.getByRole('button', { name: 'Usar sticker Listo', exact: true }).click();
        await dialog.getByRole('button', { name: 'Enviar mensaje', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-chat-message="302"] img')?.naturalWidth > 0);
        assert.equal(state.chatCalls.at(-1).sticker_id, 1);
        assert.ok(state.imageCalls.every(auth => auth === 'Bearer comms-fixture'));
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/comunicaciones-chat.png') });
        await dialog.getByRole('button', { name: 'Nuevo privado', exact: true }).click();
        await dialog.locator('[data-open-user="18"]').click();
        await log.getByText('Privado para Ivan', { exact: true }).waitFor();
        await dialog.locator('#commsChatText').fill('Mensaje reservado');
        await dialog.getByRole('button', { name: 'Enviar mensaje', exact: true }).click();
        await log.getByText('Mensaje reservado', { exact: true }).waitFor();
        assert.equal(state.chatCalls.at(-1).conversation, 2);
        await dialog.locator('[data-select-conversation="1"]').click();
        await log.getByText('Mensaje <b>seguro</b>', { exact: true }).waitFor();
        assert.doesNotMatch(await log.innerText(), /Mensaje reservado|Privado para Ivan/);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await dialog.evaluate(n => n.scrollWidth <= n.clientWidth + 1), true);
        await page.waitForFunction(() => document.querySelector('[data-chat-message="302"] img')?.naturalWidth > 0);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/comunicaciones-mobile.png') });
        await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden' });
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('comunicaciones: correo con lectura, archivo, respuesta y varios destinatarios', async () => {
    const browser = await launch();
    try {
        const { context, page, dialog, state, errors } = await setup(browser);
        await dialog.getByRole('button', { name: 'Correo', exact: true }).click();
        await dialog.locator('[data-open-mail="201"]').click();
        await dialog.locator('.comms-mail-text').filter({ hasText: '<script>window.__mailXss=1</script>' }).waitFor();
        assert.equal(await page.evaluate(() => window.__mailXss), undefined);
        await dialog.getByRole('button', { name: 'Marcar no leído', exact: true }).click();
        await dialog.getByRole('button', { name: 'Marcar leído', exact: true }).waitFor();
        assert.equal(state.mails[0].leido, false);
        await dialog.getByRole('button', { name: 'Archivar', exact: true }).click();
        await dialog.getByRole('button', { name: 'Archivados', exact: true }).click();
        await dialog.locator('[data-open-mail="201"]').click();
        await dialog.getByRole('button', { name: 'Restaurar a recibidos', exact: true }).click();
        await dialog.getByRole('button', { name: 'Recibidos', exact: true }).click();
        await dialog.locator('[data-open-mail="201"]').click();
        await dialog.getByRole('button', { name: 'Responder', exact: true }).click();
        const form = dialog.locator('[data-mail-compose]');
        assert.equal(await form.locator('[name="asunto"]').inputValue(), 'Re: Cambio de turno');
        assert.match(await form.locator('[data-mail-recipients]').innerText(), /Sharon B/);
        await form.locator('[name="texto"]').fill('Recibido, gracias.');
        state.failMail = true;
        await form.getByRole('button', { name: 'Enviar correo', exact: true }).click();
        await dialog.locator('[data-comms-status]').filter({ hasText: 'Reintenta sin cambiar el borrador' }).waitFor();
        await form.getByRole('button', { name: 'Enviar correo', exact: true }).click();
        await form.waitFor({ state: 'hidden' });
        assert.equal(state.mailCalls[0].operacion_id, state.mailCalls[1].operacion_id);
        assert.deepEqual(state.mailCalls[1].destinatarios, [18]);
        assert.equal(state.mailCalls[1].respuesta_a_id, 201);
        await dialog.getByRole('button', { name: 'Redactar', exact: true }).click();
        await form.locator('[data-recipient="18"]').check();
        await form.locator('[data-recipient="19"]').check();
        await form.locator('[name="asunto"]').fill('Correo básico');
        await form.locator('[name="texto"]').fill('Para las dos personas.');
        await form.getByRole('button', { name: 'Enviar correo', exact: true }).click();
        await form.waitFor({ state: 'hidden' });
        assert.deepEqual(state.mailCalls.at(-1).destinatarios, [18, 19]);
        await dialog.locator('[data-mail-detail] h3').filter({ hasText: 'Correo básico' }).waitFor();
        assert.match(await dialog.locator('[data-mail-detail]').innerText(), /Sharon B, Javier E/);
        await page.screenshot({ path: resolve(root, 'storage/app/ui/oficina/comunicaciones-correo.png') });
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});

test('comunicaciones: confirmar un envío propio no salta un entrante todavía sin descargar', async () => {
    const browser = await launch();
    try {
        const { context, dialog, state, errors } = await setup(browser);
        state.injectBeforeSend = true;
        await dialog.locator('#commsChatText').fill('Mi envío simultáneo');
        await dialog.getByRole('button', { name: 'Enviar mensaje', exact: true }).click();
        await dialog.locator('[data-chat-message="302"]').waitFor();
        assert.equal(state.readCalls.some(r => r.hasta_id === 302), false);
        await dialog.getByRole('button', { name: 'Actualizar', exact: true }).click();
        await dialog.locator('[data-chat-message="301"]').waitFor();
        assert.match(await dialog.locator('[data-chat-message="301"]').innerText(), /Entrante durante el envío/);
        assert.equal(await dialog.locator('[data-chat-message="302"]').count(), 1);
        assert.deepEqual(await dialog.locator('[data-chat-message]').evaluateAll(nodes => nodes.map(n => Number(n.dataset.chatMessage))), [101, 301, 302]);
        assert.deepEqual(errors, []); await context.close();
    } finally { await browser.close(); }
});

test('comunicaciones: descarta respuestas privadas tardías al cambiar de chat o de usuario', async () => {
    const browser = await launch();
    let release = () => {};
    try {
        const { context, page, dialog, state, errors } = await setup(browser);
        function hold() {
            let started;
            const ready = new Promise(r => { started = r; });
            const promise = new Promise(r => { release = r; });
            state.holdPrivate = { started, promise };
            return ready;
        }
        let ready = hold();
        await dialog.locator('[data-select-conversation="2"]').click(); await ready;
        await dialog.locator('[data-select-conversation="1"]').click();
        await dialog.locator('[data-chat-message="101"]').waitFor();
        release();
        assert.doesNotMatch(await dialog.getByRole('log').innerText(), /Privado para Ivan/);
        ready = hold();
        await dialog.locator('[data-select-conversation="2"]').click(); await ready;
        state.user = { ...user, id: 19, nombre: 'Javier E' };
        await page.evaluate(identity => {
            localStorage.setItem('estiba_wms_office_token', 'otro-usuario');
            localStorage.setItem('estiba_wms_office_identity', JSON.stringify(identity));
            window.dispatchEvent(new CustomEvent('estiba:office-session'));
        }, state.user);
        await dialog.waitFor({ state: 'hidden' }); release(); state.holdPrivate = null;
        await page.getByRole('button', { name: /Abrir chat y correo/ }).click();
        await dialog.locator('[data-chat-message="101"]').waitFor();
        assert.equal(await dialog.locator('[data-select-conversation="2"]').count(), 0);
        assert.doesNotMatch(await dialog.innerText(), /Privado para Ivan/);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { release(); await browser.close(); }
});


test('comunicaciones: crear grupo, ver fotos y moderar desde el chat', async () => {
    const browser = await launch();
    try {
        const { context, page, dialog, state, errors } = await setup(browser, { moderation: true });
        const first = dialog.locator('[data-chat-message="101"]');
        await page.waitForFunction(() => document.querySelector('[data-chat-message="101"] .comms-avatar img')?.naturalWidth > 0);
        await dialog.getByRole('button', { name: 'Nuevo grupo', exact: true }).click();
        await dialog.getByLabel('Nombre del grupo').fill('Turno B');
        await dialog.locator('[data-group-person="18"]').check();
        await dialog.getByRole('button', { name: 'Crear grupo', exact: true }).click();
        await dialog.locator('[data-conversation-title]').filter({ hasText: 'Turno B' }).waitFor();
        await dialog.getByRole('button', { name: 'Integrantes', exact: true }).click();
        await dialog.locator('[data-group-members]').getByText('Sharon B', { exact: true }).waitFor();
        await dialog.locator('[data-group-close]').click();
        await dialog.locator('[data-select-conversation="1"]').click();
        await first.getByRole('button', { name: 'Silenciar', exact: true }).click();
        const form = dialog.locator('[data-moderation-form]');
        await form.getByLabel('Duración en minutos').fill('15');
        await form.getByRole('button', { name: 'Silenciar', exact: true }).click();
        await form.waitFor({ state: 'hidden' });
        assert.deepEqual(state.moderationCalls, [{ method: 'PUT', body: { minutos: 15 } }]);
        await first.getByRole('button', { name: 'Eliminar', exact: true }).click();
        await form.getByRole('button', { name: 'Eliminar mensaje', exact: true }).click();
        await first.getByText('Mensaje eliminado por moderación').waitFor();
        assert.doesNotMatch(await first.innerText(), /Aviso/);
        await dialog.getByRole('button', { name: 'Silenciar usuario', exact: true }).click();
        await dialog.locator('[data-open-user="18"]').click();
        await form.getByRole('button', { name: 'Levantar silencio', exact: true }).click();
        await form.waitFor({ state: 'hidden' });
        assert.equal(state.moderationCalls.at(-1).method, 'DELETE');
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await dialog.evaluate(n => n.scrollWidth <= n.clientWidth + 1), true);
        assert.deepEqual(errors, []);
        await context.close();
    } finally { await browser.close(); }
});
