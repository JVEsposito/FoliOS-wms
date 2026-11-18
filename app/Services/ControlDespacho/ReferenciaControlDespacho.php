<?php

namespace App\Services\ControlDespacho;

use App\Models\Carga;
use App\Models\ControlDespacho;

/** Referencia comercial de la R-24; el código CAR sigue siendo su vínculo interno. */
final class ReferenciaControlDespacho
{
    public static function deCarga(Carga $carga): ?string
    {
        $carga->loadMissing('embarque.instructivos');
        $referencias = ($carga->embarque?->instructivos ?? collect())->sortBy('orden')
            ->pluck('numero_externo')->map(fn ($numero): string => trim((string) $numero))
            ->filter(fn (string $numero): bool => $numero !== '')->unique()->values();

        $externa = trim((string) $carga->numero_orden_externa);

        return $referencias->isNotEmpty() ? $referencias->implode(' / ') : ($externa !== '' ? $externa : null);
    }

    public static function deControl(ControlDespacho $control, ?Carga $carga = null): ?string
    {
        $guardada = trim((string) ($control->encabezado['referencia_embarque'] ?? ''));

        // Una planilla cerrada conserva la referencia confirmada, aunque cambie el calendario.
        return $guardada !== '' ? $guardada : ($control->estaCerrado() ? null : self::deCarga($carga ?? $control->carga));
    }

    public static function dePlanilla(array $planilla): ?string
    {
        $referencia = trim((string) ($planilla['encabezado']['referencia_embarque'] ?? ''));

        return $referencia !== '' ? $referencia : null;
    }

    public static function nombreArchivo(?string $referencia, string $codigoCarga, bool $fotos = false): string
    {
        $identificador = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $referencia ?? '');
        $identificador = trim($identificador, '_-');
        if ($identificador === '') {
            $identificador = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $codigoCarga);
        }

        return ($fotos ? 'R-24_fotos_' : 'R-24_').mb_strcut($identificador, 0, 180, 'UTF-8').($fotos ? '.pdf' : '.xlsx');
    }
}
