<?php

namespace App\Finanzas\Valorizacion;

use App\Finanzas\Models\Hecho;

final class ConsultaRegla
{
    public function __construct(private readonly EvaluadorRegla $evaluador) {}

    public function grupos(array $regla): array
    {
        $hechos = Hecho::query()->vigentes()->where('estado', Hecho::ESTADO_VIGENTE)
            ->where('ocurrido_at', '<=', now()->utc())
            ->whereIn('fuente', array_unique(array_column($regla['terminos'], 'fuente')))
            ->orderBy('id')->lazyById(500)->map(fn (Hecho $hecho): array => [
                'id' => $hecho->id, 'fuente' => $hecho->fuente, 'origen_id' => $hecho->origen_id, 'version' => $hecho->version,
                'cliente_id' => $hecho->cliente_id, 'cliente_nombre' => $hecho->cliente_nombre,
                'temporada_id' => $hecho->temporada_id, 'temporada_codigo' => $hecho->temporada_codigo,
                'ocurrido_at' => $hecho->ocurrido_at->toIso8601String(), 'fecha_operacional' => $hecho->fecha_operacional->toDateString(),
                'referencia' => $hecho->referencia, 'medidas' => $hecho->medidas, 'datos' => $hecho->datos,
            ]);

        return $this->evaluador->evaluar($regla, $hechos);
    }
}
