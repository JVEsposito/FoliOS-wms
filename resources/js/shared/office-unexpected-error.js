const MESSAGE = 'Ocurrió un error inesperado; recarga la página e inténtalo nuevamente.';

export function installOfficeUnexpectedErrorNotice(win = window, doc = document) {
    let notice = null;
    function showNotice() {
        // Las pantallas tienen regiones distintas; algunas viven dentro de un panel oculto.
        let region = [...doc.querySelectorAll('.toast-region, .toast-stack')]
            .find((item) => !item.closest('.is-hidden'));
        if (!region) {
            region = doc.createElement('div');
            region.className = 'toast-region';
            region.setAttribute('aria-live', 'assertive');
            doc.body.append(region);
        }
        if (notice?.isConnected) return;
        notice = doc.createElement('div');
        notice.className = 'toast toast--error';
        notice.setAttribute('role', 'alert');
        notice.textContent = MESSAGE;
        region.append(notice);
        win.setTimeout(() => { notice?.remove(); notice = null; }, 8000);
    }

    win.addEventListener('error', (event) => {
        if (event.target === win) showNotice();
    });
    win.addEventListener('unhandledrejection', showNotice);
}
