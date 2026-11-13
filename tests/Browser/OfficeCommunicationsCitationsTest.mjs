import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Citas en Comunicaciones: «#» cita operaciones y «@» menciona personas, en chat y correo.
const root = fileURLToPath(new URL('../../', import.meta.url));
const user = { id: 17, nombre: 'Ivan Sanchez', rol: 'guardia_porteria', puede_consultar_porteria: true, modulos_acceso: ['materia-prima.porteria'] };
const people = [{ id: 18, nombre: 'Sharon B' }, { id: 19, nombre: 'Javier E' }];
const date = '2026-11-13T01:00:00Z';
const carga = { tipo: 'carga', id: '9b1d6c2e-0000-4000-8000-000000000007', etiqueta: 'CAR-2611-0007', nombre_tipo: 'Carga', soy_yo: false };
const recepcion = { tipo: 'recepcion', id: '9b1d6c2e-0000-4000-8000-000000000041', etiqueta: 'REC-2611-0041 <b>x</b>', nombre_tipo: 'Recepción', soy_yo: false };
const me = { tipo: 'usuario', id: '17', etiqueta: 'Ivan Sanchez', nombre_tipo: 'Persona', soy_yo: true };

test('comunicaciones: citar con # y mencionar con @ en chat y correo, con ficha según permisos', async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-gpu'] });
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            await context.addInitScript(identity => {
                localStorage.setItem('estiba_wms_office_token', 'comms-fixture');
                localStorage.setItem('estiba_wms_office_identity', JSON.stringify(identity));
            }, user);
            const sent = { chat: [], mail: [] }; const searches = [];
            const rows = [{ id: 101, conversacion_id: 1, remitente: people[0], texto: `Revisen [[carga:${carga.id}]] y [[recepcion:${recepcion.id}]], [[usuario:17]] <img src=x onerror="window.__xss=1">`,
                referencias: [carga, recepcion, me], me_menciona: true, sticker: null, creado_at: date, operacion_id: null }];
            const mails = [];
            await context.route('http://localhost/**', async route => {
                const req = route.request(), url = new URL(req.url()), path = url.pathname, method = req.method();
                if (path === '/oficina/porteria') return route.fulfill({ contentType: 'text/html', body: readFileSync(resolve(root, 'storage/app/ui/oficina/porteria.html'), 'utf8') });
                if (path.startsWith('/build/') || path.startsWith('/brand/')) {
                    try { return route.fulfill({ body: readFileSync(resolve(root, `public${path}`)), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extname(path)] || 'application/octet-stream' }); }
                    catch { return route.fulfill({ status: 404 }); }
                }
                if (path === '/api/acceso-oficina') return route.fulfill({ json: { usuario: user } });
                if (path === '/api/usuario/perfil') return route.fulfill({ json: { data: { id: user.id, foto_perfil_version: null } } });
                if (path === '/api/oficina/contexto') return route.fulfill({ json: { data: { temporada: null, planta: 'Pruebas', avisos_cierre: [], avisos_materiales: [] } } });
                if (path === '/api/porteria/catalogos') return route.fulfill({ json: { clientes: [], ahora: date } });
                if (path === '/api/porteria/ingresos') return route.fulfill({ json: { data: [], pagina: 1, ultima_pagina: 1, total: 0, puede_registrar: true } });
                if (!path.startsWith('/api/comunicaciones/')) return route.fulfill({ status: 404, json: {} });
                if (path.endsWith('/resumen')) return route.fulfill({ json: { data: { chat_no_leido: true, menciones_sin_leer: 1, correos_no_leidos: 0 } } });
                if (path.endsWith('/usuarios')) return route.fulfill({ json: { data: people.filter(p => p.nombre.toLowerCase().includes((url.searchParams.get('buscar') || '').toLowerCase())), pagina: 1, ultima_pagina: 1 } });
                if (path === '/api/comunicaciones/referencias') {
                    searches.push(url.searchParams.get('buscar'));
                    const data = [{ ...carga, resumen: 'Pendiente · OE-77' }, { ...recepcion, resumen: 'FALCO FRUIT · Guía 4821' }].filter(r => r.etiqueta.includes((url.searchParams.get('buscar') || '').toUpperCase()));
                    return route.fulfill({ json: { data, tipos: [] } });
                }
                if (path === `/api/comunicaciones/referencias/carga/${carga.id}`) return route.fulfill({ json: { data: { tipo: 'carga', nombre_tipo: 'Carga', id: carga.id, etiqueta: carga.etiqueta,
                    datos: [{ etiqueta: 'Estado', valor: 'Pendiente' }, { etiqueta: 'Orden externa', valor: 'OE-77' }], enlace: '/oficina/frigorifico/despacho/cargas', modulo: 'Cargas', puede_privado: false } } });
                if (path === `/api/comunicaciones/referencias/recepcion/${recepcion.id}`) return route.fulfill({ status: 403, json: { message: 'No tienes acceso a las recepciones de romana. Pide a quien lo citó más detalles.' } });
                if (path === '/api/comunicaciones/referencias/usuario/17') return route.fulfill({ json: { data: { tipo: 'usuario', nombre_tipo: 'Persona', id: '17', etiqueta: 'Ivan Sanchez', datos: [{ etiqueta: 'Estado', valor: 'Activa' }], enlace: null, modulo: null, puede_privado: false } } });
                if (path.endsWith('/chat/conversaciones')) return route.fulfill({ json: { data: [{ id: 1, tipo: 'global', nombre: 'Chat global', puede_enviar: true, tiene_no_leidos: true, tiene_menciones: true, ultimo_mensaje_id: rows.at(-1).id }], pagina: 1, ultima_pagina: 1 } });
                if (path.endsWith('/chat/conversaciones/1/mensajes')) {
                    if (method === 'GET') {
                        const after = Number(url.searchParams.get('despues_id') || 0);
                        return route.fulfill({ json: { conversacion: { id: 1, tipo: 'global', nombre: 'Chat global', puede_enviar: true }, data: rows.filter(m => m.id > after), hay_mas_anteriores: false, hay_mas_nuevos: false } });
                    }
                    const payload = req.postDataJSON(); sent.chat.push(payload);
                    const referencias = [...payload.texto.matchAll(/\[\[([a-z_]+):([A-Za-z0-9-]+)\]\]/g)].map(m => m[1] === 'usuario'
                        ? { tipo: 'usuario', id: m[2], etiqueta: people.find(p => String(p.id) === m[2]).nombre, nombre_tipo: 'Persona', soy_yo: false } : { ...carga });
                    const message = { id: 102, conversacion_id: 1, remitente: user, texto: payload.texto, referencias, me_menciona: false, sticker: null, creado_at: date, operacion_id: payload.operacion_id };
                    rows.push(message); return route.fulfill({ json: { data: message } });
                }
                if (/\/chat\/conversaciones\/\d+\/leer$/.test(path)) return route.fulfill({ json: { data: { confirmado: true } } });
                if (path.endsWith('/correos')) {
                    if (method === 'POST') {
                        const payload = req.postDataJSON(); sent.mail.push(payload);
                        const mail = { id: 500, ...payload, referencias: [carga], remitente: user, destinatarios: payload.destinatarios.map(id => people.find(p => p.id === id)), es_destinatario: false, leido: true, archivado: false, me_menciona: false, creado_at: date };
                        mails.push(mail); return route.fulfill({ json: { data: mail } });
                    }
                    return route.fulfill({ json: { data: mails, pagina: 1, ultima_pagina: 1, total: mails.length } });
                }
                return route.fulfill({ status: 404, json: {} });
            });
            const page = await context.newPage(), errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto('http://localhost/oficina/porteria');
            const launcher = page.getByRole('button', { name: /Abrir chat y correo/ });
            await page.locator('[data-communications-badge]:not([hidden])').waitFor();
            assert.equal(await page.locator('[data-communications-badge]').textContent(), '@');
            assert.match(await launcher.getAttribute('aria-label'), /te mencionaron una vez/);
            await launcher.click();
            const dialog = page.getByRole('dialog', { name: 'Comunicaciones', exact: true });
            const message = dialog.locator('[data-chat-message="101"]');
            await message.waitFor();

            // Mensaje recibido: citas como botones, mención destacada y HTML ajeno sin ejecutar.
            assert.match(await message.getAttribute('class'), /is-mention/);
            assert.equal(await message.locator('.comms-cite').count(), 3);
            assert.equal(await message.locator('.comms-cite').nth(1).textContent(), '#REC-2611-0041 <b>x</b>');
            assert.equal(await message.locator('.comms-cite.is-me').textContent(), '@Ivan Sanchez');
            assert.equal(await page.evaluate(() => window.__xss), undefined);
            assert.match(await dialog.locator('[data-select-conversation="1"]').textContent(), /@/);

            // Ficha: con acceso muestra datos y enlace; sin acceso, el motivo del servidor.
            await message.getByRole('button', { name: '#CAR-2611-0007' }).click();
            const card = dialog.locator('[data-cite-card]');
            await card.getByRole('link', { name: 'Abrir en Cargas' }).waitFor();
            assert.match(await card.innerText(), /Orden externa\s+OE-77/);
            assert.equal(await card.getByRole('link', { name: 'Abrir en Cargas' }).getAttribute('target'), '_blank');
            await message.locator('.comms-cite').nth(1).click();
            await card.getByText('No tienes acceso a las recepciones de romana.', { exact: false }).waitFor();
            await card.getByRole('button', { name: 'Cerrar ficha' }).click();
            assert.equal(await card.isHidden(), true);

            // Escribir: «#» busca operaciones y «@» personas; Enter elige y Escape cierra sin cerrar el panel.
            const text = dialog.locator('#commsChatText');
            await text.click();
            await text.pressSequentially('Carguen #C');
            const suggest = dialog.locator('#commsChatSuggest');
            await suggest.getByText('Escribe al menos 2 caracteres', { exact: false }).waitFor();
            await text.pressSequentially('AR-26');
            await suggest.getByRole('option', { name: /CAR-2611-0007/ }).waitFor();
            await text.press('Enter');
            assert.equal(await text.inputValue(), 'Carguen #CAR-2611-0007 ');
            await text.pressSequentially('hoy, @jav');
            await suggest.getByRole('option', { name: /Javier E/ }).waitFor();
            await text.press('Escape');
            assert.equal(await suggest.isHidden(), true);
            assert.equal(await dialog.isVisible(), true);
            await text.press('Backspace'); await text.pressSequentially('v');
            await suggest.getByRole('option', { name: /Javier E/ }).click();
            assert.equal(await text.inputValue(), 'Carguen #CAR-2611-0007 hoy, @Javier E ');
            await dialog.getByRole('button', { name: 'Citar una operación', exact: true }).click();
            assert.equal(await text.inputValue(), 'Carguen #CAR-2611-0007 hoy, @Javier E #');
            await text.press('Backspace');
            await dialog.getByRole('button', { name: 'Enviar mensaje' }).click();
            await dialog.locator('[data-chat-message="102"]').waitFor();
            assert.equal(sent.chat[0].texto, `Carguen [[carga:${carga.id}]] hoy, [[usuario:19]]`);
            assert.equal(await dialog.locator('[data-chat-message="102"] .comms-cite').count(), 2);
            assert.equal(await text.inputValue(), '');

            // Correo: las citas viajan igual y el detalle las muestra como botones.
            await dialog.getByRole('button', { name: 'Correo', exact: true }).click();
            await dialog.getByRole('button', { name: 'Redactar' }).click();
            await dialog.getByLabel('Buscar destinatarios').fill('Sharon');
            await dialog.getByLabel('Sharon B').check();
            await dialog.getByLabel('Asunto').fill('Despacho');
            const body = dialog.locator('[data-mail-compose] textarea[name="texto"]');
            await body.click(); await body.pressSequentially('Confirmar #car-2611');
            await dialog.locator('#commsMailSuggest').getByRole('option', { name: /CAR-2611-0007/ }).waitFor();
            await body.press('Tab');
            await dialog.getByRole('button', { name: 'Enviar correo' }).click();
            await dialog.locator('[data-mail-detail] .comms-cite').waitFor();
            assert.equal(sent.mail[0].texto, `Confirmar [[carga:${carga.id}]]`);
            assert.equal(await dialog.locator('[data-mail-detail] .comms-cite').textContent(), '#CAR-2611-0007');

            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            assert.ok(searches.every(term => term.length >= 2), 'no busca con menos de 2 caracteres');
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally { await browser.close(); }
});
