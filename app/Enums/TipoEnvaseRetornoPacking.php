<?php

namespace App\Enums;

enum TipoEnvaseRetornoPacking: string
{
    case Bins = 'bins';
    case CajaTresCuartos = 'caja_3_4';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bins => 'BINS',
            self::CajaTresCuartos => 'CAJAS 3/4',
        };
    }

    public static function catalogo(): array
    {
        return array_map(fn (self $tipo): array => [
            'codigo' => $tipo->value, 'nombre' => $tipo->etiqueta(),
        ], self::cases());
    }
}
