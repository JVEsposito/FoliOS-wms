<?php

namespace App\Enums;

/**
 * Turno de proceso de Packing.
 *
 * FoliOS guarda A/B; las planillas de planta usan otros códigos para el mismo turno:
 * la PRE-SIF usa T1/T2 y el instructivo de embalaje usa AM/PM.
 */
enum TurnoProceso: string
{
    case A = 'A';
    case B = 'B';

    public function etiqueta(): string
    {
        return "Turno {$this->value}";
    }

    public function codigoPreSif(): string
    {
        return match ($this) {
            self::A => 'T1',
            self::B => 'T2',
        };
    }

    public function codigoInstructivo(): string
    {
        return match ($this) {
            self::A => 'AM',
            self::B => 'PM',
        };
    }

    /**
     * Acepta el código FoliOS (A/B), el de la PRE-SIF (T1/T2) o el del instructivo (AM/PM).
     */
    public static function desdeCodigo(?string $codigo): ?self
    {
        return match (strtoupper(trim((string) $codigo))) {
            'A', 'T1', 'AM' => self::A,
            'B', 'T2', 'PM' => self::B,
            default => null,
        };
    }

    /** @return array<int, array{codigo: string, etiqueta: string, codigo_pre_sif: string, codigo_instructivo: string}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $turno): array => [
            'codigo' => $turno->value,
            'etiqueta' => $turno->etiqueta(),
            'codigo_pre_sif' => $turno->codigoPreSif(),
            'codigo_instructivo' => $turno->codigoInstructivo(),
        ], self::cases());
    }
}
