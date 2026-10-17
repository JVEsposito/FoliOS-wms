import { filterOptions } from './validation-correction-logic.js';

/**
 * Selector con búsqueda (patrón combobox de ARIA): se abre como una lista y, al escribir,
 * filtra por cada palabra en el nombre, el detalle o el código. Flechas para moverse,
 * Enter para elegir, Escape para cerrar sin cambiar lo elegido.
 */
const VISIBLE_LIMIT = 80;
let counter = 0;

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

export function createSearchSelect(container, { label, placeholder = 'Buscar…', emptyText = 'Sin coincidencias', onChange = () => {} }) {
    counter += 1;
    const id = `search-select-${counter}`;
    container.classList.add('search-select');
    container.innerHTML = `<input class="search-select__input" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="${id}-list" aria-label="${escapeHtml(label)}" autocomplete="off" spellcheck="false" placeholder="${escapeHtml(placeholder)}"><span class="search-select__chevron" aria-hidden="true">▾</span><ul class="search-select__list" id="${id}-list" role="listbox" aria-label="${escapeHtml(label)}" hidden></ul>`;
    const input = container.querySelector('input');
    const list = container.querySelector('ul');
    let options = [];
    let value = '';
    let visible = [];
    let active = -1;
    let open = false;

    const selected = () => options.find((option) => option.value === value) || null;
    const restore = () => { input.value = selected()?.label || ''; };

    function render(query) {
        const matches = filterOptions(options, query);
        visible = matches.slice(0, VISIBLE_LIMIT);
        if (active >= visible.length) active = visible.length - 1;
        list.innerHTML = visible.length
            ? visible.map((option, position) => `<li role="option" id="${id}-option-${position}" data-position="${position}" class="search-select__option${position === active ? ' is-active' : ''}" aria-selected="${option.value === value}"><span>${escapeHtml(option.label)}</span>${option.hint ? `<small>${escapeHtml(option.hint)}</small>` : ''}</li>`).join('')
                + (matches.length > visible.length ? `<li class="search-select__more" role="presentation">${matches.length - visible.length} más: escribe para acotar</li>` : '')
            : `<li class="search-select__empty" role="presentation">${escapeHtml(emptyText)}</li>`;
        if (active >= 0) input.setAttribute('aria-activedescendant', `${id}-option-${active}`);
        else input.removeAttribute('aria-activedescendant');
        list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
    }

    function show(query = '') {
        if (input.disabled) return;
        open = true;
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        container.classList.add('is-open');
        active = query ? 0 : Math.max(0, filterOptions(options, '').findIndex((option) => option.value === value));
        render(query);
    }

    function hide() {
        open = false;
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        container.classList.remove('is-open');
    }

    function choose(option) {
        const changed = option.value !== value;
        value = option.value;
        restore();
        hide();
        if (changed) onChange(value);
    }

    input.addEventListener('focus', () => { show(''); input.select(); });
    input.addEventListener('click', () => { if (!open) show(''); });
    input.addEventListener('input', () => { active = 0; show(input.value); });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) { show(''); return; }
            const step = event.key === 'ArrowDown' ? 1 : -1;
            active = Math.min(visible.length - 1, Math.max(0, active + step));
            render(input.value === (selected()?.label || '') ? '' : input.value);
        } else if (event.key === 'Enter') {
            if (open) {
                event.preventDefault();
                if (visible[active]) choose(visible[active]);
            }
        } else if (event.key === 'Escape') {
            if (open) {
                // Cierra la lista sin cerrar el diálogo que la contiene.
                event.preventDefault();
                event.stopPropagation();
                hide();
                restore();
            }
        } else if (event.key === 'Tab') {
            hide();
            restore();
        }
    });
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        const item = event.target.closest('[data-position]');
        if (item) choose(visible[Number(item.dataset.position)]);
    });
    container.addEventListener('focusout', (event) => {
        if (!container.contains(event.relatedTarget)) { hide(); restore(); }
    });

    return {
        get value() { return value; },
        setOptions(next) {
            options = next;
            if (value && !options.some((option) => option.value === value)) value = '';
            restore();
            if (open) render(input.value);
        },
        setValue(next) {
            value = options.some((option) => option.value === next) ? next : '';
            restore();
        },
        setDisabled(disabled) {
            input.disabled = disabled;
            container.classList.toggle('is-disabled', disabled);
            if (disabled) hide();
        },
        focus() { input.focus(); },
    };
}
