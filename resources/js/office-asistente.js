import { ESQUINAS, esquinaMasCercana, renderEntendido, renderResultado, renderSugerencias } from './shared/asistente-ui.js';

// Gajo asistente de Oficina (docs/ASISTENTE_OFICINA.md): se muestra solo con la capacidad
// puede_usar_asistente, se arrastra a cualquier esquina y nunca se abre por su cuenta.

const tokenKey = 'estiba_wms_office_token';
const identityKey = 'estiba_wms_office_identity';
const UMBRAL_ARRASTRE = 6;

function leer(clave) {
    try { return localStorage.getItem(clave); } catch { return null; }
}

function identidad() {
    try { return JSON.parse(leer(identityKey) || 'null'); } catch { return null; }
}

function escape(valor) {
    return String(valor ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function claveEsquina(usuario) {
    return `estiba_wms_asistente_esquina:${usuario?.id ?? 'anonimo'}`;
}

function iniciarAsistente() {
    const raiz = document.querySelector('[data-asistente]');
    if (!raiz) return;
    const gajo = raiz.querySelector('[data-asistente-gajo]');
    const panel = raiz.querySelector('[data-asistente-panel]');
    const historial = raiz.querySelector('[data-asistente-historial]');
    const formulario = raiz.querySelector('[data-asistente-formulario]');
    const texto = raiz.querySelector('[data-asistente-texto]');
    const enviar = raiz.querySelector('[data-asistente-enviar]');
    const modo = raiz.querySelector('[data-asistente-modo]');
    let sugerenciasVigentes = [];
    let ultimaPregunta = null;
    let ultimoTexto = '';
    let ocupado = false;

    function habilitado() {
        return Boolean(identidad()?.capacidades?.puede_usar_asistente) && Boolean(leer(tokenKey));
    }

    function aplicarVisibilidad() {
        raiz.hidden = !habilitado();
        if (raiz.hidden) cerrar();
    }

    function aplicarEsquina(esquina) {
        raiz.dataset.esquina = ESQUINAS.includes(esquina) ? esquina : 'abajo-derecha';
    }

    function abrir() {
        panel.hidden = false;
        gajo.setAttribute('aria-expanded', 'true');
        texto.focus();
    }

    function cerrar() {
        if (panel.hidden) return;
        panel.hidden = true;
        gajo.setAttribute('aria-expanded', 'false');
    }

    function alternar() {
        if (panel.hidden) abrir(); else { cerrar(); gajo.focus(); }
    }

    function agregar(html, clase) {
        const bloque = document.createElement('div');
        bloque.className = clase;
        bloque.innerHTML = html;
        historial.append(bloque);
        historial.scrollTop = historial.scrollHeight;
        return bloque;
    }

    // La respuesta se lee desde su comienzo: la pregunta queda arriba del historial.
    function mostrarDesdePregunta() {
        if (!ultimaPregunta) return;
        historial.scrollTop += ultimaPregunta.getBoundingClientRect().top - historial.getBoundingClientRect().top;
    }

    function preguntar(textoVisible, guardarTexto) {
        ultimaPregunta = agregar(`<p>${escape(textoVisible)}</p>`, 'folios-asistente__pregunta');
        if (guardarTexto) ultimoTexto = textoVisible;
    }

    async function llamar(url, cuerpo) {
        const respuesta = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${leer(tokenKey)}` },
            body: JSON.stringify(cuerpo),
        });
        const datos = await respuesta.json().catch(() => ({}));
        if (!respuesta.ok) {
            const mensaje = datos?.errors?.texto?.[0] ?? datos?.message ?? 'No se pudo responder. Revisa tu conexión e inténtalo de nuevo.';
            throw new Error(respuesta.status === 403 ? 'No tienes acceso a esa consulta.' : mensaje);
        }
        return datos.data;
    }

    function mostrar(datos) {
        if (modo && datos?.modo) modo.textContent = datos.modo === 'sin_ia' ? 'Sin IA' : 'Haiku';
        if (datos?.tipo === 'sugerencias') {
            sugerenciasVigentes = datos.sugerencias ?? [];
            const busqueda = datos.busqueda
                ? `<a class="folios-asistente__enlace" href="/oficina/consultas/busqueda?q=${encodeURIComponent(datos.busqueda)}">Buscar «${escape(datos.busqueda)}» en la Búsqueda operacional</a>`
                : '';
            agregar(renderSugerencias(sugerenciasVigentes, escape, datos.mensaje) + busqueda, 'folios-asistente__respuesta');
            mostrarDesdePregunta();
            return;
        }
        agregar(renderEntendido(datos?.entendido, escape) + renderResultado(datos?.resultado, escape), 'folios-asistente__respuesta');
        mostrarDesdePregunta();
    }

    async function ejecutar(promesa) {
        ocupado = true;
        enviar.disabled = true;
        const espera = agregar('<p class="folios-asistente__cargando">Consultando…</p>', 'folios-asistente__respuesta');
        try {
            const datos = await promesa();
            espera.remove();
            mostrar(datos);
        } catch (error) {
            espera.innerHTML = `<p class="folios-asistente__error" role="alert">${escape(error.message)}</p>`;
        } finally {
            ocupado = false;
            enviar.disabled = false;
        }
    }

    formulario.addEventListener('submit', (evento) => {
        evento.preventDefault();
        const pregunta = texto.value.trim();
        if (!pregunta || ocupado) return;
        preguntar(pregunta, true);
        texto.value = '';
        ejecutar(() => llamar('/api/asistente/preguntas', { texto: pregunta }));
    });

    historial.addEventListener('click', (evento) => {
        if (evento.target.closest('[data-asistente-corregir]')) {
            // Corregir: la pregunta vuelve al cuadro para precisar periodo, cliente o consulta.
            texto.value = ultimoTexto;
            texto.focus();
            return;
        }
        const boton = evento.target.closest('[data-asistente-sugerencia]');
        if (!boton || ocupado) return;
        const sugerencia = sugerenciasVigentes[Number(boton.dataset.asistenteSugerencia)];
        if (!sugerencia) return;
        preguntar(sugerencia.titulo, false);
        ejecutar(() => llamar(`/api/asistente/consultas/${encodeURIComponent(sugerencia.clave)}`, sugerencia.parametros ?? {}));
    });

    raiz.querySelector('[data-asistente-cerrar]').addEventListener('click', () => { cerrar(); gajo.focus(); });

    // Arrastre: al soltar, el gajo se acomoda en la esquina más cercana y la recuerda.
    let inicio = null;
    let arrastrando = false;
    gajo.addEventListener('pointerdown', (evento) => {
        if (evento.button !== 0) return;
        inicio = { x: evento.clientX, y: evento.clientY };
        arrastrando = false;
        gajo.setPointerCapture(evento.pointerId);
    });
    gajo.addEventListener('pointermove', (evento) => {
        if (!inicio) return;
        if (!arrastrando && Math.hypot(evento.clientX - inicio.x, evento.clientY - inicio.y) > UMBRAL_ARRASTRE) {
            arrastrando = true;
            raiz.dataset.arrastrando = 'true';
        }
        if (arrastrando) {
            raiz.style.setProperty('--asistente-x', `${evento.clientX}px`);
            raiz.style.setProperty('--asistente-y', `${evento.clientY}px`);
        }
    });
    gajo.addEventListener('pointerup', (evento) => {
        if (!inicio) return;
        if (arrastrando) {
            const esquina = esquinaMasCercana(evento.clientX, evento.clientY, window.innerWidth, window.innerHeight);
            aplicarEsquina(esquina);
            try { localStorage.setItem(claveEsquina(identidad()), esquina); } catch { /* Preferencia opcional. */ }
        } else {
            alternar();
        }
        delete raiz.dataset.arrastrando;
        raiz.style.removeProperty('--asistente-x');
        raiz.style.removeProperty('--asistente-y');
        inicio = null;
    });
    gajo.addEventListener('pointercancel', () => {
        inicio = null;
        delete raiz.dataset.arrastrando;
    });
    gajo.addEventListener('keydown', (evento) => {
        if (evento.key === 'Enter' || evento.key === ' ') {
            evento.preventDefault();
            alternar();
        }
    });

    document.addEventListener('keydown', (evento) => {
        if (raiz.hidden) return;
        if (evento.altKey && !evento.ctrlKey && !evento.metaKey && evento.key.toLowerCase() === 'a') {
            evento.preventDefault();
            alternar();
        } else if (evento.key === 'Escape' && !panel.hidden) {
            cerrar();
            gajo.focus();
        }
    });

    aplicarEsquina(leer(claveEsquina(identidad())));
    aplicarVisibilidad();
    window.addEventListener('estiba:office-session', aplicarVisibilidad);
    window.addEventListener('storage', aplicarVisibilidad);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarAsistente);
} else {
    iniciarAsistente();
}
