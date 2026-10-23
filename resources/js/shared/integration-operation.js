/** Consulta únicamente el estado: nunca repite la solicitud que inició el trabajo. */
export async function waitIntegrationOperation(accepted, {
    headers = {}, valid = () => true, onProgress = () => {}, fetcher = fetch,
    wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)), attempts = 160,
} = {}) {
    let op = accepted.operacion;
    const check = () => { if (!valid()) throw new DOMException('La selección o sesión cambió.', 'AbortError'); };
    for (let step = 0; step < attempts; step++) {
        check();
        onProgress(op);
        if (!['en_cola', 'en_curso'].includes(op.estado)) {
            const result = op.respuesta;
            if (!result || result.codigo_http >= 400) {
                const error = new Error(result?.contenido?.message || 'La consulta no terminó. Solicita otra lectura.');
                error.status = result?.codigo_http || 410;
                throw error;
            }
            return result.contenido;
        }
        // Construir la ruta desde el identificador evita seguir una URL ajena recibida en JSON.
        if (!/^[a-f0-9-]{36}$/i.test(op.id)) throw new Error('Identificador de consulta no válido.');
        await wait(1500);
        check();
        const response = await fetcher(`/api/integraciones/operaciones/${op.id}`, { method: 'GET', cache: 'no-store', headers });
        const data = await response.json();
        check();
        if (!response.ok) {
            const error = new Error(response.status >= 500 ? 'No fue posible consultar el avance.' : data.message || 'No fue posible consultar el avance.');
            error.status = response.status;
            throw error;
        }
        op = data.operacion;
    }
    throw new Error('La consulta sigue pendiente. Puedes seguir trabajando y revisar su estado al actualizar la pantalla.');
}
