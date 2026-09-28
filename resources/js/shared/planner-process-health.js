export function plannerProcessCause(processes = {}) {
    const stopped = [];
    if (processes.worker?.estado === 'detenido') stopped.push('cola detenida');
    if (processes.scheduler?.estado === 'detenido') stopped.push('programador detenido');
    return stopped.join(' y ');
}

export function plannerProcessWarnings(processes = {}, formatTime = (value) => value) {
    const definitions = [
        ['worker', 'Procesador de cola', 'el planificador no puede recalcular'],
        ['scheduler', 'Programador de tareas', 'no se ejecutan las tareas programadas'],
    ];
    return definitions.flatMap(([key, title, consequence]) => {
        const process = processes[key];
        if (process?.estado !== 'detenido') return [];
        const when = process.ultimo_latido_at
            ? `Último latido ${formatTime(process.ultimo_latido_at)}`
            : 'Sin latido registrado';
        return [`${title} detenido. ${when}: ${consequence}.`];
    });
}
