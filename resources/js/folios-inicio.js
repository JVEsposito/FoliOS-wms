import {
    entryFromResponse,
    loginErrorMessage,
    OFFICE_IDENTITY_KEY,
    OFFICE_TOKEN_KEY,
    officeReturnPath,
} from './shared/folios-entry.js';
import { createFoliosAttention } from './shared/folios-atencion.js';

const byId = (id) => document.getElementById(id);
const elements = {
    root: byId('foliosInicio'),
    form: byId('foliosLoginForm'),
    loginError: byId('foliosLoginError'),
    errorText: byId('foliosErrorText'),
    retry: byId('foliosRetryButton'),
    logout: byId('foliosLogoutButton'),
    noOfficesName: byId('foliosSinOficinasNombre'),
};
// Oficina pedida sin sesión (/FoliOS?volver=/oficina/...): se retoma al iniciar sesión.
const query = new URLSearchParams(window.location.search);
const returnPath = officeReturnPath(query.get('volver'));
const showAttention = query.get('inicio') === '1' && !returnPath;
const attention = createFoliosAttention(elements.root);

function storage(action) {
    try {
        return action();
    } catch {
        return null;
    }
}

function show(view) {
    elements.root.dataset.inicioEstado = view;
    elements.root.querySelectorAll('[data-inicio-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.inicioPanel !== view;
    });
    if (view === 'acceso') elements.form.elements.email.focus();
}

function saveSession(token, identity) {
    storage(() => {
        localStorage.setItem(OFFICE_TOKEN_KEY, token);
        localStorage.setItem(OFFICE_IDENTITY_KEY, JSON.stringify(identity));
    });
}

function clearSession() {
    storage(() => {
        localStorage.removeItem(OFFICE_TOKEN_KEY);
        localStorage.removeItem(OFFICE_IDENTITY_KEY);
    });
}

async function request(path, { method = 'GET', token = null, body = null } = {}) {
    const headers = new Headers({ Accept: 'application/json' });
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (body) headers.set('Content-Type', 'application/json');

    try {
        const response = await fetch(path, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
            cache: 'no-store',
        });
        const data = response.status === 204 ? null : await response.json().catch(() => null);

        return { status: response.status, data };
    } catch {
        return { status: 0, data: null };
    }
}

function apply(entry, token) {
    if (entry.clearSession) clearSession();
    if (entry.identity && token) saveSession(token, entry.identity);

    if (entry.view === 'redirect' && showAttention && !entry.identity.es_cliente) {
        byId('foliosOfficeLink').href = entry.destination;
        show('inicio');
        attention.session(token);
        return;
    }

    if (entry.view === 'redirect') {
        show('cargando');
        window.location.replace(returnPath || entry.destination);
        return;
    }

    if (entry.view === 'sin-oficinas') {
        elements.noOfficesName.textContent = entry.identity?.nombre || 'Tu cuenta';
    }
    if (entry.view === 'error') elements.errorText.textContent = entry.message;
    if (entry.view === 'acceso') elements.loginError.textContent = entry.message || '';
    show(entry.view);
}

async function openSession() {
    const token = storage(() => localStorage.getItem(OFFICE_TOKEN_KEY));
    if (!token) {
        show('acceso');
        return;
    }

    show('cargando');
    const { status, data } = await request('/api/acceso-oficina', { token });
    apply(entryFromResponse(status, data), token);
}

elements.form.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.textContent = '';
    if (!elements.form.reportValidity()) return;

    const button = elements.form.querySelector('button[type="submit"]');
    button.disabled = true;
    const { status, data } = await request('/api/acceso-oficina', {
        method: 'POST',
        body: {
            email: elements.form.elements.email.value.trim(),
            password: elements.form.elements.password.value,
        },
    });
    button.disabled = false;

    if (status === 200 && data?.token) {
        elements.form.elements.password.value = '';
        const entry = entryFromResponse(status, data);
        if (showAttention && entry.view === 'redirect' && !entry.identity.es_cliente) {
            saveSession(data.token, entry.identity);
            show('cargando');
            window.location.reload();
            return;
        }
        apply(entry, data.token);
        return;
    }

    elements.loginError.textContent = loginErrorMessage(status, data);
});

elements.retry.addEventListener('click', () => void openSession());

elements.logout.addEventListener('click', async () => {
    const token = storage(() => localStorage.getItem(OFFICE_TOKEN_KEY));
    elements.logout.disabled = true;
    if (token) await request('/api/acceso-oficina', { method: 'DELETE', token });
    elements.logout.disabled = false;
    clearSession();
    show('acceso');
});

void openSession();
