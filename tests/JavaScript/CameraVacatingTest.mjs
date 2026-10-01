import assert from 'node:assert/strict';
import test from 'node:test';
import { mountCameraVacating } from '../../resources/js/shared/camera-vacating.js';

function setup(options = {}) {
    const nodes = {};
    for (const name of ['Section', 'Status', 'Start', 'Cancel', 'Dialog', 'Form', 'Title', 'Help', 'Confirmation', 'Error', 'Submit', 'Close']) {
        nodes[name] = { hidden: false, disabled: false, textContent: '', listeners: {},
            addEventListener(name, fn) { this.listeners[name] = fn; },
            showModal() { this.open = true; }, close() { this.open = false; }, reset() {},
        };
    }
    nodes.Form.elements = { motivo: { value: 'Mantención programada' }, confirmacion: { value: 'CAM-1' } };
    let camera = { id: 'camera 1', codigo: 'CAM-1', desocupacion_habilitada: true };
    let user = { puede_supervisar_camaras_productos: true };
    const calls = []; const notices = []; const refreshes = [];
    const ui = mountCameraVacating({
        root: { getElementById(id) { return nodes[id.replace('cameraVacating', '')]; } },
        currentCamera: () => camera, identity: () => user,
        api: async (...args) => { calls.push(args); return options.api?.(...args); },
        refresh: async (id) => { refreshes.push(id); return options.refresh?.(id); },
        notify: (...args) => notices.push(args),
    });
    const click = (name) => nodes[name].listeners.click();
    const submit = () => nodes.Form.listeners.submit({ preventDefault() {} });
    return { nodes, calls, notices, refreshes, ui, click, submit,
        setCamera(value) { camera = value; }, setUser(value) { user = value; }, get camera() { return camera; } };
}

test('inicio requiere permiso y ejecución habilitada; presenta progreso y bloqueo sin interpretar HTML', () => {
    const f = setup();
    f.ui.render(f.camera); assert.equal(f.nodes.Start.hidden, false);
    f.setUser({}); f.ui.render(f.camera); assert.equal(f.nodes.Start.hidden, true);
    f.click('Start'); assert.equal(f.nodes.Dialog.open, undefined);
    f.setUser({ puede_supervisar_camaras_productos: true });
    f.setCamera({ ...f.camera, desocupacion_habilitada: false });
    f.ui.render(f.camera); assert.equal(f.nodes.Start.disabled, true);
    f.click('Start'); assert.equal(f.nodes.Dialog.open, undefined);
    f.setCamera({ ...f.camera, desocupacion: { id: 'p1', activa: true, estado: 'pendiente', motivo: '<script>', pallets_evacuados: 2, pallets_objetivo: 4, pallets_restantes: 2, porcentaje_actual: 50, motivo_pendiente: 'sin_destino_compatible' } });
    f.ui.render(f.camera);
    assert.equal(f.nodes.Start.hidden, true); assert.equal(f.nodes.Cancel.hidden, false);
    assert.match(f.nodes.Status.textContent, /2 de 4 pallets trasladados/);
    assert.match(f.nodes.Status.textContent, /Sin espacio compatible en otras cámaras/);
    assert.match(f.nodes.Status.textContent, /<script>/);
});

test('confirmación exacta y cámara capturada impiden operar otra selección', async () => {
    const f = setup(); f.click('Start');
    f.nodes.Form.elements.confirmacion.value = 'cam-1'; await f.submit(); assert.equal(f.calls.length, 0);
    f.nodes.Form.elements.confirmacion.value = 'CAM-1';
    f.setCamera({ ...f.camera, id: 'camera 2' }); await f.submit(); assert.equal(f.calls.length, 0);
    assert.match(f.nodes.Error.textContent, /cambió/);
});

test('doble envío publica una vez y refresca la cámara; no repite tras un error de lectura', async () => {
    let release;
    const f = setup({ api: () => new Promise((resolve) => { release = resolve; }), refresh: () => { throw new Error('Sin conexión'); } });
    f.click('Start'); const first = f.submit(); await f.submit();
    assert.equal(f.calls.length, 1); assert.equal(f.nodes.Close.disabled, true);
    release({}); await first;
    assert.equal(f.calls[0][0], '/api/desocupaciones-camara/camera%201');
    assert.deepEqual(JSON.parse(f.calls[0][1].body), { motivo: 'Mantención programada' });
    assert.equal(f.nodes.Dialog.open, false); assert.deepEqual(f.refreshes, ['camera 1']);
    assert.match(f.notices[1][0], /operación fue registrada/);
    await f.submit(); assert.equal(f.calls.length, 1);
});

test('cancelación muestra conflicto del backend y conserva el diálogo para resolverlo', async () => {
    const f = setup({ api: () => { throw new Error('Termine la maniobra en curso'); } });
    f.setCamera({ ...f.camera, desocupacion: { id: 'p1', activa: true } });
    f.click('Cancel'); assert.equal(f.nodes.Confirmation.hidden, true);
    await f.submit(); assert.equal(f.calls[0][0], '/api/desocupaciones-camara/camera%201/cancelar');
    assert.equal(f.nodes.Dialog.open, true); assert.match(f.nodes.Error.textContent, /maniobra/);
    assert.equal(f.nodes.Submit.disabled, false); assert.equal(f.refreshes.length, 0);
});

test('ciclo completado permite nuevo inicio; emergencia impide vaciado normal', () => {
    const f = setup();
    f.setCamera({ ...f.camera, desocupacion: { id: 'p1', activa: false, estado: 'completada', pallets_evacuados: 4, pallets_objetivo: 4, pallets_restantes: 0, porcentaje_actual: 100 } });
    f.ui.render(f.camera); assert.equal(f.nodes.Start.hidden, false); assert.equal(f.nodes.Cancel.hidden, true);
    assert.match(f.nodes.Status.textContent, /lista para apagar/);
    f.setCamera({ ...f.camera, emergencia: { id: 'e1' } }); f.ui.render(f.camera);
    assert.equal(f.nodes.Start.hidden, true); f.click('Start'); assert.equal(f.nodes.Dialog.open, undefined);
});
