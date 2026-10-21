<?php

namespace App\Rrhh\Integracion;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class FechaGeoVictoria
{
    public static function normalizar(string $valor, ?string $zona): string
    {
        if (! preg_match('/^\d{14}$/', $valor)) {
            return $valor;
        } // El formato ISO con offset lo valida FechaMarcaje.
        if (! $zona || ! in_array($zona, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            self::error();
        }
        $tz = new DateTimeZone($zona);
        $fecha = DateTimeImmutable::createFromFormat('!YmdHis', $valor, $tz);
        if (! $fecha || $fecha->format('YmdHis') !== $valor) {
            self::error();
        }
        $ingenua = DateTimeImmutable::createFromFormat('!YmdHis', $valor, new DateTimeZone('UTC'))->getTimestamp();
        $offsets = [$fecha->getOffset()];
        foreach ($tz->getTransitions($fecha->getTimestamp() - 86400, $fecha->getTimestamp() + 86400) ?: [] as $t) {
            $offsets[] = $t['offset'];
        }
        $candidatos = [];
        foreach (array_unique($offsets) as $offset) {
            $candidato = (new DateTimeImmutable('@'.($ingenua - $offset)))->setTimezone($tz);
            if ($candidato->format('YmdHis') === $valor) {
                $candidatos[] = $candidato;
            }
        }
        if (count($candidatos) !== 1) {
            self::error();
        }

        return $candidatos[0]->format('Y-m-d\TH:i:sP');
    }

    private static function error(): never
    {
        throw ValidationException::withMessages(['Date' => 'Fecha de GeoVictoria sin zona confirmada, inválida o ambigua por cambio de hora. Se requiere el instante explícito del proveedor.']);
    }
}
