const profileForm = document.getElementById('accessProfileForm');
const profileTable = document.getElementById('accessProfilesTableBody');

if (profileForm && profileTable) {
    const tokenKey = 'estiba_wms_office_token';
    const state = {
        profiles: [],
        macros: [],
        tabletMacros: [],
        homes: [],
        roles: [],
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    async function api(path, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('Authorization', `Bearer ${localStorage.getItem(tokenKey) || ''}`);
        if (options.body) headers.set('Content-Type', 'application/json');
        const response = await fetch(path, { ...options, headers });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(Object.values(data?.errors || {}).flat()[0] || data?.message || 'No fue posible guardar el perfil.');
        }

        return data;
    }

    function selectedRole() {
        return state.roles.find((role) => role.clave === profileForm.elements.rol_base.value);
    }

    function isReadOnlyProfile() {
        return profileForm.elements.solo_consulta.value === '1';
    }

    function applyAccessMode(selectDefaults = false) {
        if (isReadOnlyProfile()) {
            profileForm.elements.rol_base.value = 'consulta';
            profileForm.elements.rol_base.disabled = true;
        } else {
            if (profileForm.elements.rol_base.value === 'consulta') {
                profileForm.elements.rol_base.value =
                    state.roles.find((role) => role.clave !== 'consulta')?.clave || '';
            }
            profileForm.elements.rol_base.disabled = false;
        }
        applyRoleAvailability(selectDefaults);
    }

    function selectedModules() {
        return [...profileForm.querySelectorAll('[name="modulos[]"]:checked')]
            .map((input) => input.value);
    }

    function selectedTabletModules() {
        return [...profileForm.querySelectorAll('[name="modulos_tablet[]"]:checked')]
            .map((input) => input.value);
    }

    function updateMacroState(fieldset, moduleName = 'modulos[]') {
        const macro = fieldset.querySelector('[data-macro-toggle], [data-tablet-macro-toggle]');
        const modules = [...fieldset.querySelectorAll(`[name="${moduleName}"]:not(:disabled)`)];
        const selected = modules.filter((input) => input.checked);
        macro.checked = modules.length > 0 && selected.length === modules.length;
        macro.indeterminate = selected.length > 0 && selected.length < modules.length;
        macro.disabled = modules.length === 0;
    }

    function applyTabletAvailability(selectDefaults = false) {
        const role = selectedRole();
        const readOnly = isReadOnlyProfile();
        const officeModules = new Set(selectedModules());
        const defaults = new Set(role?.modulos_tablet_sugeridos || []);
        profileForm.querySelectorAll('[name="modulos_tablet[]"]').forEach((input) => {
            const related = (input.dataset.officeModules || '').split(' ').filter(Boolean);
            const enabled = !readOnly
                && related.some((officeModule) => officeModules.has(officeModule));
            input.disabled = !enabled;
            input.closest('.access-module-option')?.classList.toggle('is-disabled', !enabled);
            if (!enabled) input.checked = false;
            else if (selectDefaults) input.checked = defaults.has(input.value);
        });
        profileForm.querySelectorAll('[data-access-tablet-macro]').forEach((fieldset) => {
            updateMacroState(fieldset, 'modulos_tablet[]');
        });
        applyHomeAvailability();
    }

    function renderHomeOptions() {
        const groups = new Map();
        state.homes.forEach((home) => {
            if (!groups.has(home.area)) groups.set(home.area, []);
            groups.get(home.area).push(home);
        });
        profileForm.elements.pantalla_inicio.innerHTML = '<option value="">Según el nivel operacional base</option>'
            + [...groups].map(([area, homes]) => `
                <optgroup label="${escapeHtml(area)}">
                    ${homes.map((home) => `<option value="${escapeHtml(home.ruta)}" data-home-modules="${escapeHtml((home.modulos_relacionados || []).join(' '))}">${escapeHtml(home.nombre)}</option>`).join('')}
                </optgroup>
            `).join('');
    }

    function applyHomeAvailability() {
        const modules = new Set(selectedModules());
        const select = profileForm.elements.pantalla_inicio;
        [...select.options].forEach((option) => {
            if (!option.value) return;
            const related = (option.dataset.homeModules || '').split(' ').filter(Boolean);
            option.disabled = !related.some((module) => modules.has(module));
        });
        if (select.selectedOptions[0]?.disabled) select.value = '';
    }

    function homeName(route) {
        const home = state.homes.find((candidate) => candidate.ruta === route);
        return home ? `${home.area} · ${home.nombre}` : 'según nivel base';
    }

    function applyRoleAvailability(selectDefaults = false) {
        const role = selectedRole();
        const available = new Set(role?.modulos_disponibles || []);
        const defaults = new Set(role?.modulos_sugeridos || []);
        profileForm.querySelectorAll('[name="modulos[]"]').forEach((input) => {
            const enabled = available.has(input.value);
            input.disabled = !enabled;
            input.closest('.access-module-option')?.classList.toggle('is-disabled', !enabled);
            if (!enabled) input.checked = false;
            else if (selectDefaults) input.checked = defaults.has(input.value);
        });
        profileForm.querySelectorAll('[data-access-macro]').forEach(updateMacroState);
        applyTabletAvailability(selectDefaults);
    }

    function renderSelector() {
        const container = document.getElementById('accessModuleSelector');
        const tabletContainer = document.getElementById('accessTabletModuleSelector');
        container.innerHTML = state.macros.map((macro) => `
            <fieldset class="access-module-group" data-access-macro="${escapeHtml(macro.clave)}">
                <legend>
                    <label>
                        <input data-macro-toggle type="checkbox">
                        <span><strong>${escapeHtml(macro.nombre)}</strong><small>${escapeHtml(macro.descripcion)}</small></span>
                    </label>
                </legend>
                <div class="access-module-options">
                    ${macro.modulos.map((module) => `
                        <label class="access-module-option">
                            <input name="modulos[]" type="checkbox" value="${escapeHtml(module.clave)}">
                            <span><strong>${escapeHtml(module.nombre)}</strong><small>${escapeHtml(module.descripcion)}</small></span>
                        </label>
                    `).join('')}
                </div>
            </fieldset>
        `).join('');

        container.querySelectorAll('[data-macro-toggle]').forEach((input) => {
            input.addEventListener('change', () => {
                const fieldset = input.closest('[data-access-macro]');
                fieldset.querySelectorAll('[name="modulos[]"]:not(:disabled)').forEach((module) => {
                    module.checked = input.checked;
                });
                updateMacroState(fieldset);
                applyTabletAvailability(false);
            });
        });
        container.querySelectorAll('[name="modulos[]"]').forEach((input) => {
            input.addEventListener('change', () => {
                updateMacroState(input.closest('[data-access-macro]'));
                applyTabletAvailability(false);
            });
        });

        tabletContainer.innerHTML = state.tabletMacros.map((macro) => `
            <fieldset class="access-module-group" data-access-tablet-macro="${escapeHtml(macro.clave)}">
                <legend>
                    <label>
                        <input data-tablet-macro-toggle type="checkbox">
                        <span><strong>${escapeHtml(macro.nombre)}</strong><small>${escapeHtml(macro.descripcion)}</small></span>
                    </label>
                </legend>
                <div class="access-module-options">
                    ${macro.modulos.map((module) => `
                        <label class="access-module-option">
                            <input
                                name="modulos_tablet[]"
                                type="checkbox"
                                value="${escapeHtml(module.clave)}"
                                data-office-modules="${escapeHtml((module.modulos_oficina_relacionados || []).join(' '))}"
                            >
                            <span><strong>${escapeHtml(module.nombre)}</strong><small>${escapeHtml(module.descripcion)}</small></span>
                        </label>
                    `).join('')}
                </div>
            </fieldset>
        `).join('');

        tabletContainer.querySelectorAll('[data-tablet-macro-toggle]').forEach((input) => {
            input.addEventListener('change', () => {
                const fieldset = input.closest('[data-access-tablet-macro]');
                fieldset.querySelectorAll('[name="modulos_tablet[]"]:not(:disabled)').forEach((module) => {
                    module.checked = input.checked;
                });
                updateMacroState(fieldset, 'modulos_tablet[]');
            });
        });
        tabletContainer.querySelectorAll('[name="modulos_tablet[]"]').forEach((input) => {
            input.addEventListener('change', () => {
                updateMacroState(input.closest('[data-access-tablet-macro]'), 'modulos_tablet[]');
            });
        });
    }

    function renderProfiles() {
        document.getElementById('accessProfilesSummary').textContent =
            `${state.profiles.length} ${state.profiles.length === 1 ? 'configurado' : 'configurados'}`;
        profileTable.innerHTML = state.profiles.map((profile) => `
            <tr>
                <td><strong>${escapeHtml(profile.codigo)} · ${escapeHtml(profile.nombre)}</strong><small>${profile.predeterminado ? 'Perfil inicial · ' : ''}${escapeHtml(profile.descripcion || 'Sin descripción')}</small></td>
                <td><span class="role-badge">${escapeHtml(profile.solo_consulta ? 'Solo consulta' : profile.rol_base_nombre)}</span></td>
                <td><strong>${profile.modulos.length} PC · ${(profile.modulos_tablet || []).length} PDA</strong><small>Inicio: ${escapeHtml(homeName(profile.pantalla_inicio))}</small></td>
                <td>${profile.usuarios_count}</td>
                <td><span class="access-status access-status--${profile.activo ? 'active' : 'inactive'}">${profile.activo ? 'Activo' : 'Inactivo'}</span></td>
                <td>${profile.protegido
                    ? '<span class="access-profile-lock">Protegido</span>'
                    : `<button data-edit-access-profile="${profile.id}" type="button">Editar</button>`}
                </td>
            </tr>
        `).join('') || '<tr class="admin-empty"><td colspan="6">No existen perfiles configurados.</td></tr>';
    }

    function resetForm() {
        profileForm.reset();
        profileForm.elements.id.value = '';
        profileForm.elements.activo.checked = true;
        profileForm.elements.solo_consulta.value = '0';
        profileForm.elements.solo_consulta.disabled = false;
        profileForm.elements.rol_base.disabled = false;
        profileForm.elements.rol_base.value =
            state.roles.find((role) => role.clave !== 'consulta')?.clave || '';
        document.getElementById('cancelAccessProfileEdit').classList.add('is-hidden');
        document.getElementById('accessProfileError').textContent = '';
        applyAccessMode(true);
    }

    function editProfile(profile) {
        profileForm.elements.id.value = profile.id;
        profileForm.elements.codigo.value = profile.codigo;
        profileForm.elements.nombre.value = profile.nombre;
        profileForm.elements.descripcion.value = profile.descripcion || '';
        profileForm.elements.solo_consulta.value = profile.solo_consulta ? '1' : '0';
        profileForm.elements.solo_consulta.disabled = profile.predeterminado;
        profileForm.elements.rol_base.value = profile.rol_base;
        profileForm.elements.rol_base.disabled = profile.predeterminado || profile.solo_consulta;
        profileForm.elements.activo.checked = profile.activo;
        applyRoleAvailability(false);
        const selected = new Set(profile.modulos);
        profileForm.querySelectorAll('[name="modulos[]"]:not(:disabled)').forEach((input) => {
            input.checked = selected.has(input.value);
        });
        profileForm.querySelectorAll('[data-access-macro]').forEach(updateMacroState);
        applyTabletAvailability(false);
        const selectedTablet = new Set(profile.modulos_tablet || []);
        profileForm.querySelectorAll('[name="modulos_tablet[]"]:not(:disabled)').forEach((input) => {
            input.checked = selectedTablet.has(input.value);
        });
        profileForm.querySelectorAll('[data-access-tablet-macro]').forEach((fieldset) => {
            updateMacroState(fieldset, 'modulos_tablet[]');
        });
        profileForm.elements.pantalla_inicio.value = profile.pantalla_inicio || '';
        applyHomeAvailability();
        document.getElementById('cancelAccessProfileEdit').classList.remove('is-hidden');
        profileForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        profileForm.elements.nombre.focus();
    }

    async function loadProfiles() {
        if (!localStorage.getItem(tokenKey)) return;
        const response = await api('/api/administracion/perfiles-acceso');
        state.profiles = response.data || [];
        state.macros = response.catalogo || [];
        state.tabletMacros = response.catalogo_tablet || [];
        state.homes = response.pantallas_inicio || [];
        state.roles = response.roles_base || [];
        profileForm.elements.rol_base.innerHTML = state.roles.map((role) =>
            `<option value="${escapeHtml(role.clave)}">${escapeHtml(role.nombre)}</option>`,
        ).join('');
        renderSelector();
        renderHomeOptions();
        renderProfiles();
        resetForm();
        window.dispatchEvent(new CustomEvent('estiba:access-profiles', {
            detail: { profiles: state.profiles },
        }));
    }

    profileForm.elements.codigo.addEventListener('input', (event) => {
        event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
    });
    profileForm.elements.solo_consulta.addEventListener('change', () => applyAccessMode(false));
    profileForm.elements.rol_base.addEventListener('change', () => applyRoleAvailability(true));
    document.getElementById('cancelAccessProfileEdit').addEventListener('click', resetForm);

    profileTable.addEventListener('click', (event) => {
        const button = event.target.closest('[data-edit-access-profile]');
        if (!button) return;
        const profile = state.profiles.find((candidate) => candidate.id === button.dataset.editAccessProfile);
        if (profile) editProfile(profile);
    });

    profileForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const error = document.getElementById('accessProfileError');
        error.textContent = '';
        const id = profileForm.elements.id.value;
        const payload = {
            codigo: profileForm.elements.codigo.value,
            nombre: profileForm.elements.nombre.value,
            descripcion: profileForm.elements.descripcion.value,
            solo_consulta: isReadOnlyProfile(),
            rol_base: profileForm.elements.rol_base.value,
            modulos: selectedModules(),
            modulos_tablet: selectedTabletModules(),
            pantalla_inicio: profileForm.elements.pantalla_inicio.value || null,
            activo: profileForm.elements.activo.checked,
        };
        if (!payload.modulos.length) {
            error.textContent = 'Selecciona al menos un módulo.';
            return;
        }
        try {
            await api(id ? `/api/administracion/perfiles-acceso/${id}` : '/api/administracion/perfiles-acceso', {
                method: id ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            await loadProfiles();
            window.dispatchEvent(new CustomEvent('estiba:access-profile-saved'));
        } catch (exception) {
            error.textContent = exception.message;
        }
    });

    window.addEventListener('estiba:office-session', (event) => {
        if (event.detail?.authenticated) void loadProfiles();
    });
    void loadProfiles();
}
