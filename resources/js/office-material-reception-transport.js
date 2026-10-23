const definitions = {
    conductor: { identity: 'rut', fields: { rut: 'conductor_rut', nombre: 'conductor_nombre', telefono: 'conductor_telefono' } },
    empresa: { identity: 'rut', fields: { rut: 'empresa_rut', nombre: 'transportista', telefono: 'empresa_telefono' } },
    vehiculo: { identity: 'patente', fields: { patente: 'patente', descripcion: 'vehiculo_descripcion' } },
};

export function normalizeTransportIdentity(value, type) {
    const text = String(value || '').replace(/[.\s-]/g, '').toUpperCase();
    return type === 'vehiculo' || !text ? text : `${text.slice(0, -1)}-${text.slice(-1)}`;
}

export function createReceptionTransport(form, api) {
    const root = form.querySelector('#materialReceptionTransport');
    if (!root) return null;
    const input = name => form.elements.namedItem(name);
    const status = type => root.querySelector(`[data-transport-status="${type}"]`);
    const lastButton = root.querySelector('#useMaterialTransportLastTrip');
    let versions = {}, revisions = {}, lastTrip = null, populated = false, dirty = false, readonly = false;

    const values = type => Object.fromEntries(Object.entries(definitions[type].fields).map(([key, name]) => [key, input(name).value.trim() || null]));
    function setFields(type, data) {
        for (const [key, name] of Object.entries(definitions[type].fields)) input(name).value = data?.[key] || '';
        versions[type] = data?.version || null;
        input(`${type}_actualizar`).checked = false;
        input(`${type}_actualizar`).disabled = readonly || !versions[type];
    }
    function changed(type, identityChanged = false) {
        dirty = true;
        revisions[type] = (revisions[type] || 0) + 1;
        if (status(type).textContent === 'Buscando datos guardados…') status(type).textContent = 'Datos editados durante la búsqueda. Puedes buscar nuevamente.';
        if (identityChanged) {
            versions[type] = null;
            input(`${type}_actualizar`).checked = false;
            input(`${type}_actualizar`).disabled = true;
            status(type).textContent = '';
            for (const [key, name] of Object.entries(definitions[type].fields)) {
                if (key !== definitions[type].identity) input(name).value = '';
            }
            if (type === 'conductor') { lastTrip = null; lastButton.classList.add('is-hidden'); }
        }
    }
    async function lookup(type) {
        if (readonly) return;
        const def = definitions[type];
        const identity = normalizeTransportIdentity(input(def.fields[def.identity]).value, type);
        if (!identity) return;
        const revision = revisions[type] = (revisions[type] || 0) + 1;
        status(type).textContent = 'Buscando datos guardados…';
        try {
            const response = await api(`/api/materiales/transporte/${type}?${new URLSearchParams({ [def.identity]: identity })}`);
            if (revisions[type] !== revision || readonly) return;
            input(def.fields[def.identity]).value = identity;
            versions[type] = response.data?.version || null;
            if (response.data) {
                setFields(type, response.data);
                status(type).textContent = 'Datos recuperados. Puedes ajustarlos para este viaje o marcar la actualización del directorio.';
            } else {
                status(type).textContent = 'Sin registro previo. Completa sus datos; se guardarán con la recepción.';
            }
            if (type === 'conductor') {
                lastTrip = response.ultimo_viaje;
                lastButton.classList.toggle('is-hidden', !lastTrip?.transporte);
                lastButton.textContent = `Usar transporte del último viaje${lastTrip?.guia ? ` (guía ${lastTrip.guia})` : ''}`;
            }
            dirty = true;
        } catch (error) {
            if (revisions[type] === revision) status(type).textContent = error.message;
        }
    }
    for (const [type, def] of Object.entries(definitions)) {
        for (const [key, name] of Object.entries(def.fields)) {
            input(name).addEventListener('input', () => changed(type, key === def.identity));
        }
        input(def.fields[def.identity]).addEventListener('change', () => { void lookup(type); });
        input(`${type}_actualizar`).addEventListener('change', () => { dirty = true; });
    }
    input('patente_carro').addEventListener('input', () => { dirty = true; });
    root.addEventListener('click', event => {
        const type = event.target.closest('[data-transport-lookup]')?.dataset.transportLookup;
        if (type) void lookup(type);
    });
    lastButton.addEventListener('click', () => {
        if (!lastTrip?.transporte || readonly) return;
        for (const type of ['empresa', 'vehiculo']) {
            revisions[type] = (revisions[type] || 0) + 1;
            setFields(type, lastTrip.transporte[type]);
            status(type).textContent = 'Datos del último viaje. Revisa que correspondan al ingreso actual.';
        }
        input('patente_carro').value = lastTrip.transporte.patente_carro || '';
        dirty = true;
    });

    return {
        populate(record) {
            revisions = Object.fromEntries(Object.keys(definitions).map(type => [type, (revisions[type] || 0) + 1]));
            versions = {}; lastTrip = null; dirty = false; populated = Boolean(record?.transporte);
            for (const type of Object.keys(definitions)) {
                const fallback = type === 'empresa' ? { nombre: record?.transportista } : type === 'vehiculo' ? { patente: record?.patente } : null;
                setFields(type, record?.transporte?.[type] || fallback);
                status(type).textContent = '';
            }
            input('patente_carro').value = record?.transporte?.patente_carro || '';
            lastButton.classList.add('is-hidden');
        },
        setReadonly(value) {
            readonly = value;
            root.querySelectorAll('input, button').forEach(node => { node.disabled = readonly; });
            for (const type of Object.keys(definitions)) input(`${type}_actualizar`).disabled = readonly || !versions[type];
        },
        payload() {
            if (!dirty && !populated) return {};
            const transport = { patente_carro: normalizeTransportIdentity(input('patente_carro').value, 'vehiculo') || null };
            for (const type of Object.keys(definitions)) {
                const data = values(type), def = definitions[type];
                data[def.identity] = normalizeTransportIdentity(data[def.identity], type) || null;
                transport[type] = { ...data, version_conocida: versions[type] || null, actualizar_directorio: input(`${type}_actualizar`).checked };
            }
            return { transporte: transport };
        },
    };
}
