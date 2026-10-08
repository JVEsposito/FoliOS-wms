let pending = false;
async function refresh() {
    const badge = document.querySelector('[data-material-replenishment-indicator]');
    const token = localStorage.getItem('estiba_wms_office_token');
    if (!badge || !token || pending) return;
    pending = true;
    try {
        const r = await fetch('/api/materiales/reposicion/resumen', { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
        if (!r.ok) { badge.hidden = true; return; }
        const { data } = await r.json();
        badge.textContent = `Reposición: ${data.quiebre} en quiebre · ${data.bajo_minimo} bajo mínimo`;
        badge.hidden = false;
    } catch { /* Conserva la última lectura; el siguiente refresco la actualiza. */ }
    finally { pending = false; }
}
document.addEventListener('DOMContentLoaded', () => { void refresh(); if (document.querySelector('[data-material-replenishment-indicator]')) setInterval(() => { if (!document.hidden) void refresh(); }, 60000); });
window.addEventListener('estiba:office-session', () => void refresh());
window.addEventListener('materiales:reposicion-actualizada', () => void refresh());
