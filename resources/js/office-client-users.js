/** Accesos → Usuarios de clientes: cuentas del portal atadas a una o varias exportadoras. */
(() => {
    const $ = (id) => document.getElementById(id);
    const form = $('clientUserForm');
    if (!form) return;
    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const read = (key) => { try { return localStorage.getItem(key); } catch { return null; } };
    const identity = () => { try { return JSON.parse(read('estiba_wms_office_identity') || 'null'); } catch { return null; } };
    const state = { users: [], clients: [], loaded: false };

    async function api(path, options = {}) {
        const response = await fetch(path, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${read('estiba_wms_office_token')}` } });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'No fue posible completar la operación.');
        return payload;
    }

    function render() {
        const canEdit = identity()?.puede_administrar_accesos === true;
        form.hidden = !canEdit;
        $('clientUsersSummary').textContent = `${state.users.length} registrado${state.users.length === 1 ? '' : 's'}`;
        $('clientUserClients').innerHTML = state.clients.map((c) => `<label class="client-user-option"><input type="checkbox" name="clientes" value="${escape(c.id)}"><span>${escape(c.nombre)} <small>${escape(c.codigo)}</small></span></label>`).join('')
            || '<p class="admin-form__hint">No hay clientes activos.</p>';
        $('clientUsersTableBody').innerHTML = state.users.map((u) => `<tr>
            <td><strong>${escape(u.nombre)}</strong><small>${escape(u.email)}</small>${u.debe_cambiar_password ? '<small>Debe cambiar su contraseña temporal</small>' : ''}</td>
            <td>${u.clientes.map((c) => escape(c.nombre)).join('<br>')}</td>
            <td>${u.activo ? 'Activo' : 'Inactivo'}</td>
            <td>${canEdit ? `<button class="secondary-button" type="button" data-edit-client-user="${u.id}">Editar</button>` : ''}</td>
        </tr>`).join('') || '<tr><td colspan="4">Aún no hay usuarios de clientes.</td></tr>';
    }

    async function load() {
        try {
            const payload = await api('/api/administracion/usuarios-clientes');
            state.users = payload.usuarios; state.clients = payload.clientes; state.loaded = true;
            render();
        } catch (error) { $('clientUserError').textContent = error.message; }
    }

    function reset() {
        form.reset();
        form.elements.id.value = '';
        $('clientUserActiveField').classList.add('is-hidden');
        $('clientUserCancel').classList.add('is-hidden');
        $('clientUserPasswordLabel').textContent = 'Contraseña temporal *';
        $('clientUserSubmit').innerHTML = 'Crear usuario de cliente <span>→</span>';
        $('clientUserError').textContent = '';
    }

    function edit(id) {
        const user = state.users.find((u) => String(u.id) === String(id));
        if (!user) return;
        reset();
        form.elements.id.value = user.id;
        form.elements.nombre.value = user.nombre;
        form.elements.email.value = user.email;
        form.elements.activo.checked = user.activo;
        const assigned = new Set(user.clientes.map((c) => c.id));
        form.querySelectorAll('[name="clientes"]').forEach((box) => { box.checked = assigned.has(box.value); });
        $('clientUserActiveField').classList.remove('is-hidden');
        $('clientUserCancel').classList.remove('is-hidden');
        $('clientUserPasswordLabel').textContent = 'Nueva contraseña temporal (opcional)';
        $('clientUserSubmit').innerHTML = 'Guardar cambios <span>→</span>';
        form.elements.nombre.focus();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const id = form.elements.id.value;
        const body = {
            nombre: form.elements.nombre.value.trim(), email: form.elements.email.value.trim(),
            password: form.elements.password.value || null,
            clientes: [...form.querySelectorAll('[name="clientes"]:checked')].map((box) => box.value),
            ...(id ? { activo: form.elements.activo.checked } : {}),
        };
        $('clientUserError').textContent = '';
        try {
            const result = await api(id ? `/api/administracion/usuarios-clientes/${encodeURIComponent(id)}` : '/api/administracion/usuarios-clientes',
                { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
            reset();
            await load();
            $('clientUserError').textContent = id && result.sesiones_revocadas ? 'Cambios guardados. Las sesiones abiertas de esta cuenta se cerraron.' : '';
        } catch (error) { $('clientUserError').textContent = error.message; }
    });
    $('clientUserCancel').addEventListener('click', reset);
    $('clientUsersTableBody').addEventListener('click', (event) => {
        const button = event.target.closest('[data-edit-client-user]');
        if (button) edit(button.dataset.editClientUser);
    });

    // Carga al abrir la sección; el panel puede estar oculto al inicio.
    const panel = $('administration-panel-client-users');
    const loadWhenVisible = () => { if (!state.loaded && !panel.hidden && read('estiba_wms_office_token')) load(); };
    new MutationObserver(loadWhenVisible).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
    loadWhenVisible();
})();
