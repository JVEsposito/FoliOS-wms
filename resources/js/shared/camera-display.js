export function cameraDisplayName(camera) {
    const name = String(camera?.nombre || '').trim();

    return name || 'Cámara sin nombre';
}

export function cameraReferenceLabel(camera) {
    const code = String(camera?.codigo || '').trim();
    const name = String(camera?.nombre || camera?.camara || '').trim();

    return [code, name && name !== code ? name : ''].filter(Boolean).join(' · ') || 'Cámara sin identificar';
}
