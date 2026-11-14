<?php

namespace App\Services\Romana;

use App\Enums\EstadoValidacionMp;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\ValidacionMp;
use Illuminate\Validation\ValidationException;

/**
 * Productores (CSG) de una recepción según Validación MP y el productor al que se entregan los envases
 * que salen en el destare. Con un solo CSG la salida es de ese productor; con varios, lo indica Romana.
 */
class ProductoresRecepcion
{
    /** @return list<array{codigo: string, nombre: ?string, productor_id: ?string}> */
    public function opciones(RecepcionRomana $recepcion): array
    {
        $validacion = ValidacionMp::query()->where('recepcion_romana_id', $recepcion->id)
            ->where('estado', EstadoValidacionMp::Validada)->with('segmentos.csg.productor')->first();

        return collect($validacion?->segmentos ?? [])
            ->map(fn (SegmentoValidacionMp $s): ?array => ($codigo = $s->csg_snapshot ?: $s->csg?->codigo) ? [
                'codigo' => (string) $codigo,
                'nombre' => $s->csg?->productor?->razon_social ?: $s->csg?->predio,
                'productor_id' => $s->csg?->productor_csg_id,
            ] : null)
            ->filter()->unique('codigo')->sortBy('codigo', SORT_NATURAL)->values()->all();
    }

    /** Productor que recibió los envases del destare: el indicado o, si hay uno solo, ese. */
    public function salida(RecepcionRomana $recepcion, ?array $opciones = null): ?array
    {
        if (! empty($recepcion->salida_productor_snapshot['codigo'])) {
            return $recepcion->salida_productor_snapshot;
        }
        $opciones ??= $this->opciones($recepcion);

        return count($opciones) === 1 ? $opciones[0] : null;
    }

    /**
     * Copia del CSG elegido para la salida. Con varios CSG y envases que salen es obligatorio;
     * con uno solo, o si el camión sale vacío, no se guarda nada.
     *
     * @throws ValidationException
     */
    public function elegirSalida(RecepcionRomana $recepcion, ?string $codigo, bool $salenEnvases, string $campo = 'salida_productor_csg'): ?array
    {
        $opciones = $this->opciones($recepcion);
        if (! $salenEnvases || count($opciones) <= 1) {
            return null;
        }
        $elegido = collect($opciones)->firstWhere('codigo', trim((string) $codigo));
        if ($elegido === null) {
            throw ValidationException::withMessages([$campo => 'El camión trae '.count($opciones).' CSG ('
                .implode(', ', array_column($opciones, 'codigo')).'): indica a qué productor se entregan los envases que salen.']);
        }

        return $elegido;
    }
}
