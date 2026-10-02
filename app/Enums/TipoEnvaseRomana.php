<?php

namespace App\Enums;

enum TipoEnvaseRomana: string
{
    case Bins = 'bins';
    case Esponjas = 'esponjas';
    case EsponjaTote = 'esponja_tote';
    case Totes = 'totes';
    case Smartpick = 'smartpick';
    case CajaTresCuartos = 'caja_3_4';
    case PalletCosechero = 'pallet_cosechero';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bins => 'Bins plástico', self::Esponjas => 'Esponja de bins',
            self::EsponjaTote => 'Esponja de tote', self::Totes => 'Tote',
            self::Smartpick => 'Smartpick', self::CajaTresCuartos => 'Caja 3/4',
            self::PalletCosechero => 'Pallet cosechero',
        };
    }

    public function orden(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function contieneFruta(): bool
    {
        return in_array($this, [self::Bins, self::Totes, self::Smartpick, self::CajaTresCuartos], true);
    }

    public static function catalogo(): array
    {
        return array_map(fn (self $tipo): array => [
            'codigo' => $tipo->value, 'nombre' => $tipo->etiqueta(),
            'orden' => $tipo->orden(), 'contiene_fruta' => $tipo->contieneFruta(),
        ], self::cases());
    }
}
