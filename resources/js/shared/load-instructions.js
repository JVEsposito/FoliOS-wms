export function instructionLabel(instruction) {
    return instruction.numero_externo || `Instructivo ${instruction.orden}`;
}

export function selectedInstruction(load, selectedId) {
    const groups = load?.segregaciones || [];
    return groups.find((group) => group.id === selectedId) || (groups.length === 1 ? groups[0] : null);
}

export function availableInstructionSlots(load, selectedId) {
    const remaining = Math.max(0, 26 - Number(load?.total_folios || 0));
    if (!load?.segregaciones?.length) return remaining;
    const group = selectedInstruction(load, selectedId);
    if (!group) return 0;
    return group.pallets_programados == null ? remaining
        : Math.min(remaining, Math.max(0, group.pallets_programados - group.pallets_asignados));
}

export function groupedLoadFolios(load) {
    const groups = load.segregaciones || [];
    if (!groups.length) return [{ instruction: null, folios: load.folios || [] }];
    const knownIds = new Set(groups.map((group) => group.id));
    const result = groups.map((instruction) => ({ instruction,
        folios: (load.folios || []).filter((folio) => folio.instructivo_embarque_id === instruction.id) }));
    const unassigned = (load.folios || []).filter((folio) => !knownIds.has(folio.instructivo_embarque_id));
    if (unassigned.length) result.push({ instruction: null, folios: unassigned });
    return result;
}
