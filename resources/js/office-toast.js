// Un dialog modal ocupa la capa superior: los avisos fuera de él no son visibles.
export function showOfficeToast(region, message, error = false, duration = 5000) {
    const dialog = document.querySelector('dialog:modal');
    let host = region;
    if (dialog) {
        host = dialog.querySelector('[data-dialog-toasts]');
        if (!host) {
            host = document.createElement('div');
            host.className = 'toast-region toast-region--dialog';
            host.dataset.dialogToasts = '';
            host.setAttribute('aria-live', 'polite');
            dialog.append(host);
        }
    }
    const item = document.createElement('div');
    item.className = `toast${error ? ' toast--error' : ''}`;
    item.textContent = message;
    host.append(item);
    window.setTimeout(() => item.remove(), duration);
    return item;
}
