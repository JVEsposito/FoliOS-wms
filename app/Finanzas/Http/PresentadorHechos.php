<?php

namespace App\Finanzas\Http;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;

/** Forma JSON de hechos y lecturas para Oficina. */
final class PresentadorHechos
{
    public function __construct(private readonly CatalogoFuentes $fuentes) {}

    /** @return array<string, mixed> */
    public function hecho(Hecho $hecho): array
    {
        return [
            'id' => $hecho->id,
            'fuente' => $hecho->fuente,
            'fuente_nombre' => ($this->fuentes->todas()[$hecho->fuente] ?? null)?->nombre() ?? $hecho->fuente,
            'origen_id' => $hecho->origen_id,
            'version' => $hecho->version,
            'vigente' => $hecho->vigente,
            'estado' => $hecho->estado,
            'motivo_version' => $hecho->motivo_version,
            'cliente_id' => $hecho->cliente_id,
            'cliente_nombre' => $hecho->cliente_nombre,
            'temporada_codigo' => $hecho->temporada_codigo,
            'referencia' => $hecho->referencia,
            'ocurrido_at' => $hecho->ocurrido_at->toIso8601String(),
            'termina_at' => $hecho->termina_at?->toIso8601String(),
            'fecha_operacional' => $hecho->fecha_operacional->toDateString(),
            'medidas' => $hecho->medidas,
            'medidas_info' => ($this->fuentes->todas()[$hecho->fuente] ?? null)?->medidas() ?? [],
            'dimensiones_info' => $this->fuentes->contexto($hecho->fuente)['dimensiones'],
            'datos' => $hecho->datos,
            'capturado_at' => $hecho->capturado_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function lectura(Lectura $lectura): array
    {
        return [
            'id' => $lectura->id,
            'origen' => $lectura->origen,
            'conciliacion' => $lectura->conciliacion,
            'estado' => $lectura->estado,
            'solicitada_por' => $lectura->solicitada_por_nombre,
            'iniciada_at' => $lectura->iniciada_at->toIso8601String(),
            'terminada_at' => $lectura->terminada_at?->toIso8601String(),
            'duracion_ms' => $lectura->duracion_ms,
            'resumen' => $lectura->resumen ?? [],
            'error' => $lectura->error,
        ];
    }
}
