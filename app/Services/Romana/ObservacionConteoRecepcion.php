<?php

namespace App\Services\Romana;

use App\Enums\TipoRecepcionRomana;
use App\Models\RecepcionRomana;

/**
 * Lo que Validación MP encontró distinto de la guía, en palabras para la hoja de pesaje que
 * firma el chofer (RPR-01) y para el expediente de Romana. Se arma desde lo registrado, así
 * que una corrección posterior del conteo también se refleja.
 */
class ObservacionConteoRecepcion
{
    /** @return array<int, string> */
    public function lineas(RecepcionRomana $recepcion): array
    {
        $recepcion->loadMissing('detallesEnvases');
        $detalles = $recepcion->detallesEnvases
            ->filter(fn ($detalle): bool => $detalle->cantidad_validada !== null)
            ->sortBy(fn ($detalle): int => $detalle->tipo_envase->orden());
        $lineas = [];
        foreach ($detalles as $detalle) {
            $nombre = $detalle->tipo_envase->etiqueta();
            $diferencia = (int) $detalle->cantidad_validada - (int) $detalle->cantidad_declarada;
            if ($diferencia !== 0) {
                $lineas[] = sprintf(
                    '%s: Validación MP contó %d y la guía declaraba %d (%d %s).',
                    $nombre, (int) $detalle->cantidad_validada, (int) $detalle->cantidad_declarada,
                    abs($diferencia), $diferencia > 0 ? 'más' : 'menos',
                );
            }
            if ((int) $detalle->cantidad_vacia > 0) {
                $lineas[] = sprintf(
                    '%s: %d de %d llegaron vacíos. Cuentan para la cuenta corriente y la tara, no para el peso promedio.',
                    $nombre, (int) $detalle->cantidad_vacia, (int) $detalle->cantidad_validada,
                );
            }
        }
        if ($lineas === []) {
            return [];
        }
        $lineas[] = $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases
            ? 'El neto por envase se calculó con lo contado en Validación MP.'
            : 'La tara de envases, el neto y el peso promedio se calculan con lo contado en Validación MP.';
        if ($recepcion->peso_neto_por_envase !== null && $recepcion->tipo_envase_calculo_neto) {
            $tipo = $recepcion->detallesEnvases->first(fn ($d): bool => $d->tipo_envase->value === $recepcion->tipo_envase_calculo_neto);
            $lineas[] = sprintf(
                'Peso promedio por envase con fruta (%s): %s kg sobre %d.',
                $tipo?->tipo_envase->etiqueta() ?? $recepcion->tipo_envase_calculo_neto,
                number_format((float) $recepcion->peso_neto_por_envase, 3, ',', '.'),
                (int) $recepcion->cantidad_envase_calculo_neto,
            );
        }

        return $lineas;
    }

    /**
     * Versión breve para la observación de cierre del RPR-01: dos líneas, para que las firmas
     * sigan en la misma hoja.
     */
    public function resumen(RecepcionRomana $recepcion): ?string
    {
        $recepcion->loadMissing('detallesEnvases');
        $partes = $recepcion->detallesEnvases
            ->filter(fn ($d): bool => $d->cantidad_validada !== null
                && ((int) $d->cantidad_validada !== (int) $d->cantidad_declarada || (int) $d->cantidad_vacia > 0))
            ->sortBy(fn ($d): int => $d->tipo_envase->orden())
            ->map(function ($d): string {
                $detalle = collect([
                    (int) $d->cantidad_validada !== (int) $d->cantidad_declarada ? 'guía '.(int) $d->cantidad_declarada : null,
                    (int) $d->cantidad_vacia > 0 ? (int) $d->cantidad_vacia.' vacíos' : null,
                ])->filter()->implode('; ');

                return $d->tipo_envase->etiqueta().' '.(int) $d->cantidad_validada.' ('.$detalle.')';
            });
        if ($partes->isEmpty()) {
            return null;
        }
        $lineas = ['Validación MP contó: '.$partes->implode(', ').'.'];
        $acumulativo = $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases;
        $calculo = $acumulativo ? 'Neto por envase con lo contado.' : 'Tara y neto con lo contado.';
        if ($recepcion->peso_neto_por_envase !== null && $recepcion->tipo_envase_calculo_neto) {
            $tipo = $recepcion->detallesEnvases->first(fn ($d): bool => $d->tipo_envase->value === $recepcion->tipo_envase_calculo_neto);
            $calculo .= sprintf(' Promedio sin vacíos: %s kg por %s con fruta (%d).',
                number_format((float) $recepcion->peso_neto_por_envase, 3, ',', '.'),
                $tipo?->tipo_envase->etiqueta() ?? $recepcion->tipo_envase_calculo_neto, (int) $recepcion->cantidad_envase_calculo_neto);
        }
        $lineas[] = $calculo;

        return implode("\n", $lineas);
    }
}
