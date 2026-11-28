const DEFAULT_JITTER_RATIO = 0.15;
const DEFAULT_MAX_BACKOFF_MS = 5 * 60 * 1000;

function positiveNumber(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number : fallback;
}

export function operationalPollingDelay({
    intervalMs,
    consecutiveFailures = 0,
    jitterRatio = DEFAULT_JITTER_RATIO,
    maxBackoffMs = DEFAULT_MAX_BACKOFF_MS,
    random = Math.random,
}) {
    const interval = positiveNumber(intervalMs, 30_000);
    const maximum = Math.max(interval, positiveNumber(maxBackoffMs, DEFAULT_MAX_BACKOFF_MS));
    const failures = Math.max(0, Math.floor(Number(consecutiveFailures) || 0));
    const backoff = Math.min(maximum, interval * (2 ** Math.min(failures, 4)));
    const ratio = Math.min(0.45, Math.max(0, Number(jitterRatio) || 0));
    const sample = Math.min(1, Math.max(0, Number(random()) || 0));
    const jitter = backoff * ratio * ((sample * 2) - 1);

    return Math.max(250, Math.round(backoff + jitter));
}

/**
 * Coordina refrescos operacionales sin superponer solicitudes ni concentrar
 * a todos los clientes en el mismo segundo.
 */
export function createOperationalPoller(task, options = {}) {
    if (typeof task !== 'function') {
        throw new TypeError('La tarea de refresco debe ser una función.');
    }

    const intervalMs = positiveNumber(options.intervalMs, 30_000);
    const maxBackoffMs = positiveNumber(options.maxBackoffMs, DEFAULT_MAX_BACKOFF_MS);
    const jitterRatio = options.jitterRatio ?? DEFAULT_JITTER_RATIO;
    const resumeDelayMs = positiveNumber(options.resumeDelayMs, 350);
    const canRun = typeof options.canRun === 'function' ? options.canRun : () => true;
    const onError = typeof options.onError === 'function' ? options.onError : () => {};
    const documentTarget = options.documentTarget ?? globalThis.document ?? null;
    const windowTarget = options.windowTarget ?? globalThis.window ?? null;
    const navigatorTarget = options.navigatorTarget ?? globalThis.navigator ?? null;
    const random = options.random ?? Math.random;
    const setTimer = options.setTimer ?? globalThis.setTimeout;
    const clearTimer = options.clearTimer ?? globalThis.clearTimeout;

    let timer = null;
    let running = false;
    let stopped = true;
    let listening = false;
    let consecutiveFailures = 0;

    const isHidden = () => options.pauseWhenHidden !== false && documentTarget?.hidden === true;
    const isOffline = () => options.pauseWhenOffline !== false && navigatorTarget?.onLine === false;

    function clearScheduled() {
        if (timer === null) return;
        clearTimer(timer);
        timer = null;
    }

    function schedule(delay = null) {
        clearScheduled();
        if (stopped || isHidden() || isOffline()) return;

        const wait = delay ?? operationalPollingDelay({
            intervalMs,
            consecutiveFailures,
            jitterRatio,
            maxBackoffMs,
            random,
        });
        timer = setTimer(() => {
            timer = null;
            void execute();
        }, wait);
    }

    async function execute({ force = false } = {}) {
        if (stopped || running) return false;
        if (!force && (isHidden() || isOffline())) return false;
        if (!force && !canRun()) {
            schedule();
            return false;
        }

        running = true;
        try {
            await task();
            consecutiveFailures = 0;
            return true;
        } catch (error) {
            consecutiveFailures += 1;
            onError(error);
            return false;
        } finally {
            running = false;
            schedule();
        }
    }

    function resume() {
        if (stopped || isHidden() || isOffline()) return;
        schedule(operationalPollingDelay({
            intervalMs: resumeDelayMs,
            jitterRatio: 0.45,
            maxBackoffMs: resumeDelayMs * 2,
            random,
        }));
    }

    function handleVisibilityChange() {
        if (isHidden()) clearScheduled();
        else resume();
    }

    function handleOnline() {
        resume();
    }

    function handleOffline() {
        clearScheduled();
    }

    function listen() {
        if (listening) return;
        documentTarget?.addEventListener?.('visibilitychange', handleVisibilityChange);
        windowTarget?.addEventListener?.('online', handleOnline);
        windowTarget?.addEventListener?.('offline', handleOffline);
        listening = true;
    }

    function unlisten() {
        if (!listening) return;
        documentTarget?.removeEventListener?.('visibilitychange', handleVisibilityChange);
        windowTarget?.removeEventListener?.('online', handleOnline);
        windowTarget?.removeEventListener?.('offline', handleOffline);
        listening = false;
    }

    return {
        start({ immediate = false, initialDelayMs = null } = {}) {
            if (!stopped) return;
            stopped = false;
            listen();
            if (immediate) void execute();
            else schedule(initialDelayMs);
        },
        stop() {
            stopped = true;
            clearScheduled();
            unlisten();
        },
        runNow() {
            clearScheduled();
            return execute({ force: true });
        },
        isRunning() {
            return running;
        },
    };
}
