export function canSuperviseEmergency(identity) {
    return identity?.puede_supervisar_camaras_productos === true;
}

export function confirmsCameraCode(entered, code) {
    return entered === code;
}

export function emergencyEndpoint(cameraId, action) {
    const base = `/api/evacuaciones-emergencia/${encodeURIComponent(cameraId)}`;
    return action === 'cancelar' ? `${base}/cancelar` : base;
}

export function emergencyError(data, fallback = 'No fue posible completar la operación.') {
    return data?.message || Object.values(data?.errors || {}).flat()[0] || fallback;
}
