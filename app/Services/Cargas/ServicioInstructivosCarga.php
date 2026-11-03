<?php

namespace App\Services\Cargas;

use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Embarque;
use App\Models\InstructivoEmbarque;
use DomainException;
use Illuminate\Support\Collection;

class ServicioInstructivosCarga
{
    /** Se llama con la carga bloqueada: carga → embarque → instructivos. */
    public function instructivos(Carga $carga): Collection
    {
        $embarque = Embarque::query()->where('carga_id', $carga->id)->lockForUpdate()->first();

        return $embarque ? $embarque->instructivos()->orderBy('id')->lockForUpdate()->get() : collect();
    }

    /** @param list<string> $excluirAsignaciones */
    public function paraAsignacion(Carga $carga, ?string $id, int $cantidad, array $excluirAsignaciones = []): ?InstructivoEmbarque
    {
        $instructivos = $this->instructivos($carga);
        if ($instructivos->isEmpty() && $id === null) {
            return null;
        }
        if ($id === null && $instructivos->count() === 1) {
            $id = $instructivos->first()->id;
        }
        $instructivo = $instructivos->firstWhere('id', $id);
        if (! $instructivo) {
            throw new DomainException($id === null
                ? 'Selecciona el instructivo al que pertenecen estos pallets.'
                : 'El instructivo seleccionado no pertenece al embarque de esta orden de preparación.');
        }
        $actuales = CargaFolio::query()->where('carga_id', $carga->id)
            ->where('instructivo_embarque_id', $id)->whereHas('reservaActiva')
            ->whereNotIn('id', $excluirAsignaciones)->count();
        if ($instructivo->cantidad_pallets !== null && $actuales + $cantidad > $instructivo->cantidad_pallets) {
            throw new DomainException(sprintf('El instructivo %s programa %d pallets y ya tiene %d asignados. Revisa su cantidad en el calendario antes de agregar más.',
                $instructivo->numero_externo ?: 'N° '.$instructivo->orden, $instructivo->cantidad_pallets, $actuales));
        }

        return $instructivo;
    }
}
