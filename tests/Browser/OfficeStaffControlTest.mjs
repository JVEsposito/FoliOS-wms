import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const identity = {nombre:'Supervisión',rol:'supervisor_frio',modulos_acceso:['frigorifico.control-personal'],puede_controlar_personal_frigorifico:true};
const fixture = () => ({personas:[{id:'persona-1',nombre:'Ana de prueba',identificador:'INT-001',sector:'Cámaras',funcion:'Camarera',jornada_base_minutos:480,activo:true,version:1}],sectores:['Cámaras'],data:{meta:{mes:'2026-11',zona_horaria:'America/Santiago',criterio:'Jornadas del mes de ingreso. Pendientes y anuladas no suman horas.'},resumen:[{persona_id:'persona-1',nombre:'Ana de prueba',identificador:'INT-001',jornadas_cerradas:0,pendientes:1,minutos_trabajados:0,minutos_extra:0}],jornadas:[{id:'jornada-1',persona_id:'persona-1',persona:{nombre:'Ana de prueba',identificador:'INT-001'},ingreso:'2026-11-04T20:00',salida:null,colacion_minutos:60,jornada_base_minutos:480,sector:'Cámaras',estado:'pendiente',version:1,minutos_trabajados:null,minutos_extra:null}]}});

for (const width of [1280,390]) test(`Control de personal: cierre nocturno, conflicto y descarga en ${width}px`, async () => {
    const browser = await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,args:['--no-sandbox','--disable-gpu']});
    try {
        const context = await browser.newContext({viewport:{width,height:950}});
        await context.addInitScript(() => {localStorage.setItem('estiba_wms_office_token','fixture');});
        const response = fixture(); const writes=[]; let conflict=false; let exportUrl;
        await context.route('http://localhost/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/oficina/frigorifico/control-personal') return route.fulfill({body:readFileSync(resolve(root,'storage/app/ui/oficina/control-personal.html'),'utf8'),contentType:'text/html'});
            if (path.startsWith('/build/') || path.startsWith('/brand/')) {try {return route.fulfill({body:readFileSync(resolve(root,`public${path}`)),contentType:({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.woff2':'font/woff2','.png':'image/png'})[extname(path)] || 'application/octet-stream'});} catch {return route.fulfill({status:404});}}
            if (path === '/api/acceso-oficina') return route.fulfill({json:{usuario:identity}});
            if (path === '/api/frigorifico/control-personal') return route.fulfill({json:response});
            if (path.endsWith('/jornadas/jornada-1')) {
                const body=route.request().postDataJSON(); writes.push(body);
                if (conflict) return route.fulfill({status:409,json:{message:'Otro supervisor modificó este registro.'}});
                Object.assign(response.data.jornadas[0],body,{version:2,estado:'cerrada',minutos_trabajados:557,minutos_extra:77});
                Object.assign(response.data.resumen[0],{jornadas_cerradas:1,pendientes:0,minutos_trabajados:557,minutos_extra:77});
                return route.fulfill({json:{data:response.data.jornadas[0]}});
            }
            if (path.endsWith('/exportar')) {exportUrl=route.request().url(); return route.fulfill({body:Buffer.from('PK test export'),contentType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});}
            return route.fulfill({json:{data:{planta:'Pruebas',temporada:null,avisos_materiales:[],avisos_cierre:[]}}});
        });
        const page=await context.newPage(); const errors=[];page.on('pageerror',(e)=>errors.push(e.message));
        await page.clock.install({time:new Date('2026-11-06T12:00:00Z')});
        await page.goto('http://localhost/oficina/frigorifico/control-personal');
        await page.locator('#staffStatus').filter({hasText:'Control actualizado'}).waitFor();
        assert.match(await page.locator('#staffRecords').innerText(),/Sin salida/);
        await page.getByRole('button',{name:'Registrar salida',exact:true}).click();
        assert.equal(await page.locator('#staffEntryBase').inputValue(),'08:00');
        await page.locator('#staffOut').fill('2026-11-05T06:17');
        await page.locator('#staffReason').fill('Salida informada por supervisor');
        await page.locator('#staffEntrySave').click();
        await page.locator('#staffStatus').filter({hasText:'Registro guardado'}).waitFor();
        assert.equal(writes.length,1);assert.equal(writes[0].colacion_minutos,60);assert.equal(writes[0].version,1);
        assert.match(await page.locator('#staffSummary').innerText(),/09:17.*01:17/s);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true);
        if (process.env.FOLIOS_UI_CAPTURE_DIR) await page.screenshot({path:resolve(process.env.FOLIOS_UI_CAPTURE_DIR,`personal-${width}.png`),fullPage:true});
        const downloaded=page.waitForEvent('download');await page.locator('#staffExportSummary').click();await downloaded;assert.match(exportUrl,/vista=resumen/);
        conflict=true;await page.getByRole('button',{name:'Corregir',exact:true}).click();
        await page.locator('#staffReason').fill('Verificación pendiente');await page.locator('#staffEntrySave').click();
        await page.locator('#staffEntryError').filter({hasText:'El borrador se conserva'}).waitFor();
        assert.equal(await page.locator('#staffReason').inputValue(),'Verificación pendiente');assert.equal(writes[1].version,2);
        assert.deepEqual(errors,[]);await context.close();
    } finally {await browser.close();}
});
