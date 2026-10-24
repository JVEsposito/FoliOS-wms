import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
const root = fileURLToPath(new URL('../../', import.meta.url));
const markup = () => readFileSync(resolve(root, 'storage/app/ui/oficina/turnos.html'), 'utf8');
const functions = [ ['recepcion_tuneles','Salidas de túnel'], ['recepcion_packing','Recepción packing'], ['separacion_cargas','Separación de cargas'], ['preparacion_sag','Preparación SAG'], ['apoyo_camaras','Apoyo en cámaras'] ].map(([codigo,nombre]) => ({codigo,nombre}));
const fixture = () => ({ data: [{ id: 'turno-1', nombre: 'Turno mañana', inicio_at: '2026-10-24T09:00:00Z', fin_at: '2026-10-24T17:00:00Z', version: 1, vigente: true,
    asignaciones: [{ user_id: 1, nombre: 'Camarero A', funcion_principal: 'recepcion_tuneles', funciones_apoyo: [], preferencia: null, disponible: true }],
    labores: [{ id: 'labor-1', camarero: 'Camarero C', tablet: 'Tablet C', titulo: 'Separar carga A', estado: 'esperando_dependencias', motivo: '2 pallets pendientes de recepción. Puedes continuar otra carga.', espera: { porcentaje_fisico: 60 } }] }],
    catalogo: { funciones: functions, usuarios: [1,2,3,4].map((id) => ({ id, nombre: `Camarero ${'ABCD'[id-1]}` })), tuneles: [{id:'tunel-1',codigo:'T1',nombre:'Túnel 1'}], cargas: [{id:'carga-a',codigo:'Carga A'},{id:'carga-b',codigo:'Carga B'}], sag: [] }, meta: { zona_horaria:'America/Santiago', ahora:'2026-10-24T15:00:00Z',vigente_id:'turno-1' } });
for (const width of [1280,390]) test(`Configura cuatro camareros, conserva borradores y muestra conflictos en ${width}px`, async () => {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args:['--no-sandbox','--disable-gpu'] });
    try {
        const context = await browser.newContext({ viewport:{width,height:950} });
        await context.addInitScript(() => { localStorage.setItem('estiba_wms_office_token','fixture'); localStorage.setItem('estiba_wms_office_identity',JSON.stringify({nombre:'Supervisor',rol:'supervisor_frio',puede_supervisar_camaras_productos:true})); });
        let response = fixture(); const writes=[]; let conflict=false;
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/frigorifico/labores-turno') return route.fulfill({ body:markup(),contentType:'text/html' });
            if (path.startsWith('/build/') || path.startsWith('/brand/')) { try { return route.fulfill({body:readFileSync(resolve(root,`public${path}`)),contentType:({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.woff2':'font/woff2'})[extname(path)] || 'application/octet-stream'}); } catch { return route.fulfill({status:404}); } }
            if (path === '/api/labores-turno') {
                if (route.request().method() === 'GET') return route.fulfill({json:response});
                const body=route.request().postDataJSON(); writes.push(body);
                if (conflict) return route.fulfill({status:409,json:{message:'Otro supervisor cambió este turno.'}});
                response.data[0] = {...response.data[0],...body,version:2,asignaciones:body.asignaciones.map((a)=>({...a,nombre:response.catalogo.usuarios.find((u)=>u.id===a.user_id).nombre}))};
                return route.fulfill({json:{data:response.data[0]}});
            }
            return route.fulfill({json:{data:{planta:'Pruebas',temporada:null,avisos_cierre:[],avisos_materiales:[]}}});
        });
        const page=await context.newPage(); const errors=[]; page.on('pageerror',(e)=>errors.push(e.message));
        await page.goto('http://localhost/oficina/frigorifico/labores-turno');
        await page.locator('#shiftStatus').filter({hasText:'Turnos actualizados'}).waitFor();
        assert.match(await page.locator('#shiftJobs').innerText(),/2 pallets pendientes.*Puedes continuar otra carga/);
        await page.locator('#shiftName').fill('Turno piloto');
        await page.locator('#shiftReload').click();
        await page.locator('#shiftStatus').filter({hasText:'cambios sin guardar se conservan'}).waitFor();
        assert.equal(await page.locator('#shiftName').inputValue(),'Turno piloto');
        for (const [id,funcion] of [[2,'recepcion_packing'],[3,'separacion_cargas'],[4,'separacion_cargas']]) {
            await page.locator('#shiftAddUser').selectOption(String(id)); await page.locator('#shiftAdd').click();
            await page.locator(`[data-row="${id-1}"] [data-field="funcion_principal"]`).selectOption(funcion);
        }
        await page.locator('[data-row="2"] [data-field="preferencia"]').selectOption('carga-a');
        await page.locator('[data-row="3"] [data-field="preferencia"]').selectOption('carga-b');
        await page.locator('[data-row="0"] [data-support-select]').selectOption('separacion_cargas');
        await page.locator('[data-row="0"] [data-add-support]').click();
        await page.locator('#shiftSave').click();
        await page.locator('#shiftStatus').filter({hasText:'Turno guardado'}).waitFor();
        assert.equal(writes.length,1); assert.equal(writes[0].asignaciones.length,4);
        assert.deepEqual(writes[0].asignaciones[0].funciones_apoyo,['separacion_cargas']);
        assert.deepEqual(writes[0].asignaciones[3].preferencia,{tipo:'carga',id:'carga-b'});
        assert.match(await page.locator('[data-row="3"] h3').innerText(),/Camarero D/);
        await page.evaluate(()=>window.scrollTo(0,0));
        if (process.env.FOLIOS_UI_CAPTURE_DIR) await page.screenshot({path:resolve(process.env.FOLIOS_UI_CAPTURE_DIR,`turnos-${width}.png`),fullPage:true});
        conflict=true; await page.locator('#shiftName').fill('Borrador conservado'); await page.locator('#shiftSave').click();
        await page.locator('#shiftError').filter({hasText:'Tu borrador se conserva'}).waitFor();
        assert.equal(await page.locator('#shiftName').inputValue(),'Borrador conservado');
        assert.equal(writes[1].version,2);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth),true);
        assert.deepEqual(errors,[]); await context.close();
    } finally { await browser.close(); }
});
