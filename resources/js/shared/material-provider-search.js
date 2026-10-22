import { normalizeSearchText } from './material-item-search.js';

const compactCode = (value) => normalizeSearchText(value).replace(/[._-]/g, '');

/** Busca por palabras, sin distinguir tildes ni el formato de los códigos. */
export function filterMaterialProviders(providers, { query = '', clientId = '', status = 'todos' } = {}) {
    const words = normalizeSearchText(query).split(/\s+/).filter(Boolean);

    return providers.filter((provider) => {
        if (status === 'activo' && !provider.activo) return false;
        if (status === 'inactivo' && provider.activo) return false;
        const clients = provider.clientes || [];
        if (clientId && !clients.some((client) => client.id === clientId)) return false;

        const text = normalizeSearchText([
            provider.codigo_interno, provider.codigo, provider.nombre, provider.codigo_externo,
            ...clients.flatMap((client) => [client.codigo, client.nombre, ...(client.categorias || [])]),
        ].filter(Boolean).join(' '));
        const codes = [provider.codigo_interno, provider.codigo].filter(Boolean).map(compactCode);

        return words.every((word) => text.includes(word)
            || codes.some((code) => compactCode(word) && code.includes(compactCode(word))));
    });
}
