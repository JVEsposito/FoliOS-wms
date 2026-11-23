// Funciones puras de la configuración del asistente; las prueba tests/JavaScript/AsistenteConfiguracionTest.mjs.
export const DECISIONES = {
    conectar_haiku: 'Conectar Haiku',
    seguir_sin_ia: 'Seguir sin IA',
    datos_insuficientes: 'Faltan datos',
    corregir_reglas: 'Corregir reglas',
};
export const ESTADOS = { respondida: 'Respondida', sin_datos: 'Sin datos', sugerencias: 'Sin consulta' };

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function usd(value, decimals = 2) {
    return `US$ ${Number(value || 0).toLocaleString('es-CL', { minimumFractionDigits: decimals, maximumFractionDigits: Math.max(decimals, 4) })}`;
}

export function percent(value) {
    return `${(Number(value || 0) * 100).toLocaleString('es-CL', { maximumFractionDigits: 1 })} %`;
}

export function renderGate(report) {
    const r = report.registro;
    const a = report.reinterpretado;
    const b = report.banco;
    const sinRespuesta = (report.sin_respuesta || []).slice(0, 8).map((t) => `<li><strong>${t.veces}×</strong> ${escapeHtml(t.texto)}</li>`).join('');
    return `
        <p class="assistant-settings__decision" data-decision="${escapeHtml(report.decision)}"><strong>${escapeHtml(DECISIONES[report.decision] || report.decision)}.</strong> ${escapeHtml(report.motivo)}</p>
        <dl class="assistant-settings__facts">
            <div><dt>Preguntas</dt><dd>${r.total} de ${r.usuarios} usuarios</dd></div>
            <div><dt>Sin consulta, como se registró</dt><dd>${percent(r.proporcion_sugerencias)}</dd></div>
            <div><dt>Sin consulta, con las reglas de hoy</dt><dd>${percent(a.proporcion_sugerencias)}</dd></div>
            <div><dt>Banco</dt><dd>${b.correctas} correctas · ${b.falsas} falsas</dd></div>
        </dl>
        ${sinRespuesta ? `<p class="eyebrow">SIGUEN SIN CONSULTA</p><ul class="assistant-settings__list">${sinRespuesta}</ul>` : ''}`;
}
